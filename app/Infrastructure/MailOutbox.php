<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class MailOutbox
{
    public function __construct(private readonly Database $db, private readonly Config $config, private readonly Crypto $crypto, private readonly ClockInterface $clock, private readonly MailerInterface $mailer) {}

    public function enqueue(string $kind, array $user, array $token): void
    {
        if (!$this->config->mailEnabled) { throw new \DomainException('Der E-Mail-Versand ist noch nicht eingerichtet.'); }
        if (!$this->db->inTransaction()) { throw new \LogicException('Mailauftrag benötigt eine fachliche Transaktion.'); }
        $path = $kind === 'password_reset' ? '/passwort-neu' : '/email-bestaetigen';
        $link = $this->config->url . $path . '#token=' . $token['token'];
        $blocked = $this->config->environment !== 'production' && !str_ends_with(mb_strtolower($user['email']), '@example.test');
        $this->db->run('INSERT INTO notification_outbox(event_key,kind,user_id,token_id,recipient,secret_payload_ciphertext,key_id,status,available_at,message_id,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', [
            $kind.':'.$token['id'],$kind,$user['id'],$token['id'],$user['email'],
            $blocked ? null : $this->crypto->encrypt(json_encode(['link'=>$link],JSON_THROW_ON_ERROR),'mail'),$this->config->keyId,
            $blocked ? 'blocked' : 'queued',$this->clock->now()->getTimestamp(),Crypto::token().'@'.parse_url($this->config->url,PHP_URL_HOST),$this->clock->now()->getTimestamp(),
        ]);
    }

    public function work(int $limit = 10): array
    {
        if (!$this->config->mailEnabled) { throw new \DomainException('Der E-Mail-Versand ist deaktiviert. Es wurden keine Nachrichten versendet.'); }
        if ($this->db->inTransaction()) { throw new \LogicException('Kein SMTP-Versand innerhalb einer Transaktion.'); }
        $results = ['submitted'=>0,'cancelled'=>0,'ambiguous'=>0,'blocked'=>0];
        for ($i=0; $i<$limit; $i++) {
            $row = $this->db->immediate(function () use (&$results): ?array {
                $now = $this->clock->now()->getTimestamp();
                // An expired SMTP lease may already have transmitted its message.
                $results['ambiguous'] += $this->db->run("UPDATE notification_outbox SET status='ambiguous',secret_payload_ciphertext=NULL,last_error_redacted='Worker-Lease abgelaufen; vor Neuversand klären' WHERE status='sending' AND lease_until<=?",[$now])->rowCount();
                $row = $this->db->one("SELECT * FROM notification_outbox WHERE status='queued' AND available_at<=? ORDER BY id LIMIT 1",[$now]);
                if ($row === null) { return null; }
                $lease = Crypto::token();
                $this->db->run("UPDATE notification_outbox SET status='sending',attempts=attempts+1,lease_token=?,lease_until=? WHERE id=? AND status='queued'",[$lease,$now+300,$row['id']]);
                $row['lease_token'] = $lease;
                return $row;
            });
            if ($row === null) { break; }
            $token = $this->db->one('SELECT t.*,u.status AS user_status,u.email AS current_email FROM email_verification_tokens t JOIN users u ON u.id=t.user_id WHERE t.id=?',[$row['token_id']]);
            $now = $this->clock->now()->getTimestamp();
            if ($token === null || $token['user_status'] !== 'active' || $token['consumed_at'] !== null || $token['revoked_at'] !== null
                || (int)$token['expires_at'] <= $now || $token['current_email'] !== $row['recipient']) {
                $results[$this->finish($row,'cancelled')]++; continue;
            }
            if ($this->config->environment !== 'production' && !str_ends_with(mb_strtolower($row['recipient']),'@example.test')) {
                $results[$this->finish($row,'blocked')]++; continue;
            }
            try {
                $payload = json_decode($this->crypto->decrypt($row['secret_payload_ciphertext'],'mail',$row['key_id']),true,512,JSON_THROW_ON_ERROR);
                $title = $row['kind'] === 'password_reset' ? 'Passwort zurücksetzen' : 'E-Mail-Adresse bestätigen';
                $intro = $row['kind'] === 'password_reset' ? 'Du hast ein neues Passwort angefordert.' : 'Bitte bestätige deine E-Mail-Adresse für dein Corpsakademie-Konto.';
                $link = htmlspecialchars($payload['link'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
                $message = (new Email())->from($this->config->mailFrom)->to($row['recipient'])->subject('Corpsakademie · '.$title)
                    ->text($intro."\n\n".$payload['link']."\n\nDieser Link ist einmalig und 30 Minuten gültig. Wenn du die Nachricht nicht angefordert hast, kannst du sie ignorieren.")
                    ->html('<html lang="de"><body><h1>'.htmlspecialchars($title).'</h1><p>'.htmlspecialchars($intro).'</p><p><a href="'.$link.'">'.htmlspecialchars($title).'</a></p><p>Einmalig nutzbar und 30 Minuten gültig. Falls du diese Nachricht nicht angefordert hast, kannst du sie ignorieren.</p></body></html>');
                $message->getHeaders()->addIdHeader('Message-ID',$row['message_id']);
                if ($this->db->one("SELECT id FROM notification_outbox WHERE id=? AND status='sending' AND lease_token=? AND lease_until>?",[$row['id'],$row['lease_token'],$this->clock->now()->getTimestamp()])===null) {
                    $results[$this->finish($row,'ambiguous','Lease vor SMTP-Übergabe verloren')]++; continue;
                }
                $this->mailer->send($message);
                $results[$this->finish($row,'submitted')]++;
            } catch (\Throwable $error) {
                // Do not log SMTP exception text: it may contain the message/link.
                $results[$this->finish($row,'ambiguous',get_debug_type($error))]++;
            }
        }
        $now = $this->clock->now()->getTimestamp();
        $this->db->run('INSERT INTO worker_heartbeats(worker_name,last_started_at,last_success_at,last_error_at,release_version) VALUES(?,?,?,?,?) ON CONFLICT(worker_name) DO UPDATE SET last_started_at=excluded.last_started_at,last_success_at=excluded.last_success_at,last_error_at=excluded.last_error_at,release_version=excluded.release_version',[
            'identity-mail',$now,$results['ambiguous'] === 0 ? $now : null,$results['ambiguous'] > 0 ? $now : null,'0.1.0',
        ]);
        return $results;
    }

    private function finish(array $row, string $status, ?string $error = null): string
    {
        $now = $this->clock->now()->getTimestamp();
        $changed = $this->db->run('UPDATE notification_outbox SET status=?,secret_payload_ciphertext=NULL,submitted_at=?,last_error_redacted=?,lease_token=NULL,lease_until=NULL WHERE id=? AND status=? AND lease_token=? AND lease_until>?',[
            $status,$status==='submitted' ? $now : null,$error,$row['id'],'sending',$row['lease_token'],$now,
        ])->rowCount();
        if ($changed === 0) {
            $this->db->run("UPDATE notification_outbox SET status='ambiguous',secret_payload_ciphertext=NULL,last_error_redacted='Abschluss nach Lease-Verfall; vor Neuversand klären' WHERE id=? AND status='sending' AND lease_token=?",[$row['id'],$row['lease_token']]);
            return 'ambiguous';
        }
        return $status;
    }
}
