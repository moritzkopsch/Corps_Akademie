<?php
declare(strict_types=1);
namespace Corpsakademie\Application;

use Corpsakademie\Domain\Identity\Accounts;
use Corpsakademie\Infrastructure\{Audit,Config,Crypto,Database};
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class Newsletter
{
    public function __construct(private readonly Database $db, private readonly Config $config, private readonly Crypto $crypto, private readonly Accounts $accounts, private readonly Audit $audit, private readonly ClockInterface $clock, private readonly MailerInterface $mailer) {}

    public function overview(int $actor): array
    {
        $this->authorize($actor);
        return ['campaigns'=>$this->db->run("SELECT c.*,COUNT(d.id) AS recipients,COUNT(*) FILTER (WHERE d.state='submitted') AS submitted,COUNT(*) FILTER (WHERE d.state='queued') AS waiting,COUNT(*) FILTER (WHERE d.state='ambiguous') AS ambiguous FROM newsletter_campaigns c LEFT JOIN newsletter_deliveries d ON d.campaign_id=c.id GROUP BY c.id ORDER BY c.id DESC LIMIT 100")->fetchAll(),
            'active'=>(int)$this->db->run("SELECT COUNT(*) FROM newsletter_subscribers WHERE state='active'")->fetchColumn()];
    }

    public function campaign(int $actor,int $id): ?array
    {
        $this->authorize($actor);
        return $this->db->one('SELECT * FROM newsletter_campaigns WHERE id=?',[$id]);
    }

    public function save(int $actor,array $values,?int $id): int
    {
        $subject=Catalog::text($values,'subject',180); $body=Catalog::text($values,'body',18000);
        if (preg_match('/[\r\n]/',$subject)) { throw new \DomainException('Der Betreff muss einzeilig sein.'); }
        return $this->db->immediate(function() use ($actor,$values,$id,$subject,$body): int {
            $this->authorize($actor); $now=$this->clock->now()->getTimestamp();
            if ($id===null) {
                $id=(int)$this->db->run("INSERT INTO newsletter_campaigns(subject,body,state,created_by,created_at,updated_at) VALUES(?,?,'draft',?,?,?) RETURNING id",[$subject,$body,$actor,$now,$now])->fetchColumn();
            } else {
                $count=$this->db->run("UPDATE newsletter_campaigns SET subject=?,body=?,row_version=row_version+1,updated_at=? WHERE id=? AND state='draft' AND row_version=?",[$subject,$body,$now,$id,$values['row_version']??''])->rowCount();
                if ($count!==1) { throw new \DomainException('Dieser Entwurf wurde geändert oder bereits freigegeben. Bitte neu laden.'); }
            }
            $this->audit->record('newsletter.draft_saved',$actor,(string)$id,[],'campaign');
            return $id;
        });
    }

    public function queue(int $actor,int $id,int $version,bool $confirmed): void
    {
        if (!$this->config->mailEnabled) { throw new \DomainException('Zuerst den E-Mail-Versand einrichten.'); }
        if (!$confirmed) { throw new \DomainException('Bitte den Versand an bestätigte Abonnenten ausdrücklich freigeben.'); }
        $this->db->immediate(function() use ($actor,$id,$version): void {
            $this->authorize($actor); $now=$this->clock->now()->getTimestamp();
            $row=$this->db->one("SELECT * FROM newsletter_campaigns WHERE id=? AND state='draft' AND row_version=?",[$id,$version]);
            if ($row===null) { throw new \DomainException('Entwurf geändert oder bereits freigegeben. Bitte neu laden.'); }
            $subscribers=$this->db->run("SELECT id,subscription_version FROM newsletter_subscribers WHERE state='active'")->fetchAll();
            if ($subscribers===[]) { throw new \DomainException('Es gibt noch keine bestätigten Abonnenten.'); }
            foreach ($subscribers as $subscriber) {
                $this->db->run("INSERT INTO newsletter_deliveries(campaign_id,subscriber_id,subscription_version,state,message_id) VALUES(?,?,?,'queued',?)",[$id,$subscriber['id'],$subscriber['subscription_version'],Crypto::token().'@'.parse_url($this->config->url,PHP_URL_HOST)]);
            }
            $this->db->run("UPDATE newsletter_campaigns SET state='queued',row_version=row_version+1,queued_at=?,updated_at=? WHERE id=?",[$now,$now,$id]);
            $this->audit->record('newsletter.queued',$actor,(string)$id,['recipients'=>count($subscribers)],'campaign');
        });
    }

    public function cancel(int $actor,int $id): void
    {
        $this->db->immediate(function() use ($actor,$id): void {
            $this->authorize($actor);
            if ($this->db->run("UPDATE newsletter_campaigns SET state='cancelled',row_version=row_version+1,updated_at=? WHERE id=? AND state IN ('draft','queued')",[$this->clock->now()->getTimestamp(),$id])->rowCount()!==1) { throw new \DomainException('Diese Rundmail kann nicht mehr gestoppt werden.'); }
            $this->db->run("UPDATE newsletter_deliveries SET state='cancelled' WHERE campaign_id=? AND state='queued'",[$id]);
            $this->audit->record('newsletter.cancelled',$actor,(string)$id,[],'campaign');
        });
    }

    public function work(int $limit=1): array
    {
        if (!$this->config->mailEnabled) { throw new \DomainException('E-Mail-Versand ist deaktiviert.'); }
        if ($limit<1 || $limit>10) { throw new \DomainException('Pro Lauf sind 1 bis 10 Newsletter erlaubt.'); }
        $results=['submitted'=>0,'cancelled'=>0,'ambiguous'=>0,'blocked'=>0];
        for ($i=0;$i<$limit;$i++) {
            $row=$this->db->immediate(function() use (&$results): ?array {
                $now=$this->clock->now()->getTimestamp();
                $results['ambiguous']+=$this->db->run("UPDATE newsletter_deliveries SET state='ambiguous',error_type='LeaseExpired' WHERE state='sending' AND lease_until<=?",[$now])->rowCount();
                $row=$this->db->one("SELECT d.*,s.email,s.state AS subscription_state,s.subscription_version AS current_version,c.subject,c.body,c.state AS campaign_state FROM newsletter_deliveries d JOIN newsletter_subscribers s ON s.id=d.subscriber_id JOIN newsletter_campaigns c ON c.id=d.campaign_id WHERE d.state='queued' ORDER BY d.id LIMIT 1");
                if ($row===null) { return null; }
                if ($this->config->environment==='production') {
                    $next=$this->db->one('SELECT next_at FROM newsletter_rate_limit WHERE id=1');
                    if ($next!==null && (int)$next['next_at']>$now) { return null; }
                    $this->db->run('INSERT INTO newsletter_rate_limit(id,next_at) VALUES(1,?) ON CONFLICT(id) DO UPDATE SET next_at=excluded.next_at',[$now+300]);
                }
                $row['lease_token']=Crypto::token();
                $this->db->run("UPDATE newsletter_deliveries SET state='sending',lease_token=?,lease_until=? WHERE id=?",[$row['lease_token'],$now+300,$row['id']]);
                return $row;
            });
            if ($row===null) { break; }
            if ($row['subscription_state']!=='active' || $row['current_version']!==$row['subscription_version'] || $row['campaign_state']!=='queued') { $this->finish($row,'cancelled',$results); continue; }
            if ($this->config->environment!=='production' && !str_ends_with(mb_strtolower($row['email']),'@example.test')) { $this->finish($row,'blocked',$results); continue; }
            try {
                $token=Crypto::token();
                $this->db->run('UPDATE newsletter_deliveries SET unsubscribe_hash=? WHERE id=? AND lease_token=?',[Crypto::hash($token),$row['id'],$row['lease_token']]);
                $link=$this->config->url.'/newsletter/abmelden#token='.$token;
                $message=(new Email())->from($this->config->newsletterFrom)->replyTo($this->config->mailReplyTo)->to($row['email'])->subject('Corpsakademie · '.$row['subject'])->text($row['body']."\n\n—\nCorpsakademie\nSie erhalten diese Nachricht aufgrund Ihrer bestätigten Newsletter-Anmeldung.\nAbmelden: ".$link."\nImpressum: ".$this->config->url.'/impressum');
                $message->getHeaders()->addIdHeader('Message-ID',$row['message_id']);
                // Recheck immediately before SMTP. No database transaction is held over the network.
                $eligible=$this->db->one("SELECT d.id FROM newsletter_deliveries d JOIN newsletter_subscribers s ON s.id=d.subscriber_id JOIN newsletter_campaigns c ON c.id=d.campaign_id WHERE d.id=? AND d.state='sending' AND d.lease_token=? AND d.lease_until>? AND s.state='active' AND s.subscription_version=d.subscription_version AND c.state='queued'",[$row['id'],$row['lease_token'],$this->clock->now()->getTimestamp()]);
                if ($eligible===null) { $this->finish($row,'cancelled',$results); continue; }
                $this->mailer->send($message);
                $this->finish($row,'submitted',$results);
            } catch (\Throwable $error) { $this->finish($row,'ambiguous',$results,get_debug_type($error)); }
        }
        $this->db->run("UPDATE newsletter_campaigns SET state='completed' WHERE state='queued' AND NOT EXISTS (SELECT 1 FROM newsletter_deliveries d WHERE d.campaign_id=newsletter_campaigns.id AND d.state IN ('queued','sending'))");
        return $results;
    }

    private function finish(array $row,string $state,array &$results,?string $error=null): void
    {
        $now=$this->clock->now()->getTimestamp();
        // Ambiguous SMTP outcomes are never retried automatically.
        if ($this->db->run("UPDATE newsletter_deliveries SET state=?,submitted_at=?,error_type=?,lease_token=NULL,lease_until=NULL WHERE id=? AND state='sending' AND lease_token=? AND lease_until>?",[$state,$state==='submitted'?$now:null,$error,$row['id'],$row['lease_token'],$now])->rowCount()===1) { $results[$state]++; }
        else { $this->db->run("UPDATE newsletter_deliveries SET state='ambiguous',error_type='LeaseExpired' WHERE id=? AND state='sending' AND lease_token=?",[$row['id'],$row['lease_token']]); $results['ambiguous']++; }
    }

    private function authorize(int $actor): void
    {
        if (!$this->accounts->can($actor,'newsletter.manage')) { throw new \DomainException('Für die Newsletter-Verwaltung fehlt die Berechtigung.'); }
    }
}
