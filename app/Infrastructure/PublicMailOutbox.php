<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class PublicMailOutbox
{
    public function __construct(private readonly Database $db, private readonly Config $config, private readonly Crypto $crypto, private readonly ClockInterface $clock, private readonly MailerInterface $mailer) {}

    public function enqueue(string $event, string $recipient, string $subject, string $text, ?int $tokenId = null): void
    {
        if (!$this->config->mailEnabled) { throw new \DomainException('Der E-Mail-Versand ist noch nicht eingerichtet.'); }
        if (!$this->db->inTransaction()) { throw new \LogicException('Mailauftrag benötigt eine Transaktion.'); }
        $blocked=$this->config->environment!=='production' && !str_ends_with(mb_strtolower($recipient),'@example.test');
        $this->db->run('INSERT INTO public_mail_outbox(event_key,token_id,recipient,payload_ciphertext,key_id,status,message_id,created_at) VALUES(?,?,?,?,?,?,?,?)',[
            $event,$tokenId,$recipient,$blocked?null:$this->crypto->encrypt(json_encode(['subject'=>$subject,'text'=>$text],JSON_THROW_ON_ERROR),'public-mail'),$this->config->keyId,
            $blocked?'blocked':'queued',Crypto::token().'@'.parse_url($this->config->url,PHP_URL_HOST),$this->clock->now()->getTimestamp(),
        ]);
    }

    public function work(int $limit = 10): array
    {
        if (!$this->config->mailEnabled) { throw new \DomainException('Der E-Mail-Versand ist deaktiviert. Es wurden keine Nachrichten versendet.'); }
        if ($this->db->inTransaction()) { throw new \LogicException('Kein SMTP innerhalb einer Transaktion.'); }
        $results=['submitted'=>0,'cancelled'=>0,'ambiguous'=>0,'blocked'=>0];
        for ($i=0;$i<$limit;$i++) {
            $row=$this->db->immediate(function() use (&$results): ?array {
                $now=$this->clock->now()->getTimestamp();
                $results['ambiguous']+=$this->db->run("UPDATE public_mail_outbox SET status='ambiguous',payload_ciphertext=NULL,last_error_redacted='Lease abgelaufen; vor Neuversand klären' WHERE status='sending' AND lease_until<=?",[$now])->rowCount();
                $row=$this->db->one("SELECT * FROM public_mail_outbox WHERE status='queued' ORDER BY id LIMIT 1");
                if ($row===null) { return null; }
                $row['lease_token']=Crypto::token();
                $this->db->run("UPDATE public_mail_outbox SET status='sending',lease_token=?,lease_until=? WHERE id=? AND status='queued'",[$row['lease_token'],$now+300,$row['id']]);
                return $row;
            });
            if ($row===null) { break; }
            if ($row['token_id']!==null && $this->db->one('SELECT id FROM public_tokens WHERE id=? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>?',[$row['token_id'],$this->clock->now()->getTimestamp()])===null) { $results[$this->finish($row,'cancelled')]++; continue; }
            if ($this->config->environment!=='production' && !str_ends_with(mb_strtolower($row['recipient']),'@example.test')) { $results[$this->finish($row,'blocked')]++; continue; }
            try {
                $payload=json_decode($this->crypto->decrypt($row['payload_ciphertext'],'public-mail',$row['key_id']),true,512,JSON_THROW_ON_ERROR);
                $message=(new Email())->from($this->config->mailFrom)->replyTo($this->config->mailReplyTo)->to($row['recipient'])->subject('Corpsakademie · '.$payload['subject'])->text($payload['text']);
                $message->getHeaders()->addIdHeader('Message-ID',$row['message_id']);
                if ($this->db->one("SELECT id FROM public_mail_outbox WHERE id=? AND status='sending' AND lease_token=? AND lease_until>?",[$row['id'],$row['lease_token'],$this->clock->now()->getTimestamp()])===null) { $results[$this->finish($row,'ambiguous')]++; continue; }
                $this->mailer->send($message);
                $results[$this->finish($row,'submitted')]++;
            } catch (\Throwable $error) { $results[$this->finish($row,'ambiguous',get_debug_type($error))]++; }
        }
        $now=$this->clock->now()->getTimestamp();
        $this->db->run('INSERT INTO worker_heartbeats(worker_name,last_started_at,last_success_at,last_error_at,release_version) VALUES(?,?,?,?,?) ON CONFLICT(worker_name) DO UPDATE SET last_started_at=excluded.last_started_at,last_success_at=excluded.last_success_at,last_error_at=excluded.last_error_at,release_version=excluded.release_version',['public-mail',$now,$results['ambiguous']===0?$now:null,$results['ambiguous']>0?$now:null,'0.2.0']);
        return $results;
    }

    private function finish(array $row, string $status, ?string $error = null): string
    {
        $now=$this->clock->now()->getTimestamp();
        $changed=$this->db->run("UPDATE public_mail_outbox SET status=?,payload_ciphertext=NULL,submitted_at=?,last_error_redacted=?,lease_token=NULL,lease_until=NULL WHERE id=? AND status='sending' AND lease_token=? AND lease_until>?",[$status,$status==='submitted'?$now:null,$error,$row['id'],$row['lease_token'],$now])->rowCount();
        if ($changed===0) {
            $this->db->run("UPDATE public_mail_outbox SET status='ambiguous',payload_ciphertext=NULL,last_error_redacted='Abschluss nach Lease-Verfall; vor Neuversand klären' WHERE id=? AND status='sending' AND lease_token=?",[$row['id'],$row['lease_token']]);
            return 'ambiguous';
        }
        return $status;
    }
}
