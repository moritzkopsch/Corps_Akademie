<?php
declare(strict_types=1);
namespace Corpsakademie\Application;

use Corpsakademie\Domain\Identity\{Accounts,RateLimiter};
use Corpsakademie\Infrastructure\{Audit,CatalogRepository,Config,Crypto,Database,InquiryRepository,PublicMailOutbox};
use Psr\Clock\ClockInterface;

final class PublicRequests
{
    public const CONSENT='public-2026-10-v1';

    public function __construct(private readonly Database $db, private readonly CatalogRepository $catalog, private readonly InquiryRepository $store, private readonly PublicMailOutbox $mail, private readonly Crypto $crypto, private readonly Config $config, private readonly RateLimiter $limits, private readonly Accounts $accounts, private readonly Audit $audit, private readonly ClockInterface $clock) {}

    public function submit(array $values, string $kind, string $ip): void
    {
        if (!in_array($kind,['seminar','contact'],true)) { throw new \LogicException('Unbekannter Anfragetyp.'); }
        $email=$this->email($values); $this->consent($values);
        $nonce=Catalog::text($values,'submission',43);
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$nonce)) { throw new \DomainException('Bitte das Formular neu öffnen.'); }
        $submission=Crypto::hash($nonce);
        if ($this->store->submission($submission)!==null) { return; }
        $details=['name'=>Catalog::text($values,'name',120),'corps'=>Catalog::text($values,'corps',160,$kind==='seminar'),
            'role'=>Catalog::text($values,'role',100,false),'phone'=>Catalog::text($values,'phone',50,false),
            'message'=>Catalog::text($values,'message',4000,$kind==='contact')];
        $seminar=null;
        if ($kind==='seminar') {
            $slug=Catalog::text($values,'seminar',100);
            $seminar=$this->catalog->seminar($slug);
            if ($seminar===null) { throw new \DomainException('Dieses Seminar ist derzeit nicht anfragbar.'); }
            $participants=filter_var($values['participants']??'',FILTER_VALIDATE_INT);
            if ($participants===false || $participants<1 || $participants>500) { throw new \DomainException('Bitte eine Teilnehmerzahl zwischen 1 und 500 angeben.'); }
            $date=Catalog::text($values,'date',10,false);
            $parsed=$date===''?false:\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('Europe/Berlin'));
            $today=$this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
            if ($date!=='' && ($parsed===false || $parsed->format('Y-m-d')!==$date || $date<$today || $date>$this->clock->now()->modify('+2 years')->format('Y-m-d'))) { throw new \DomainException('Bitte einen gültigen Wunschtermin innerhalb der nächsten zwei Jahre wählen.'); }
            $mode=Catalog::text($values,'referent_mode',30);
            if (!in_array($mode,['recommend','one_of','team'],true)) { throw new \DomainException('Bitte eine gültige Referentenauswahl treffen.'); }
            $selected=$values['referents']??[];
            if (!is_array($selected) || count($selected)>20) { throw new \DomainException('Bitte die Referentenauswahl prüfen.'); }
            $eligible=$this->catalog->referents(true,(int)$seminar['id']); $names=[];
            foreach (array_unique($selected,SORT_REGULAR) as $id) {
                if (!is_string($id) || !ctype_digit($id)) { throw new \DomainException('Bitte gültige Referenten wählen.'); }
                $found=array_values(array_filter($eligible,static fn(array $r): bool=>(int)$r['id']===(int)$id));
                if ($found===[]) { throw new \DomainException('Ein Referent ist diesem Seminar derzeit nicht zugeordnet.'); }
                $names[]=$found[0]['name'];
            }
            if ($mode!=='recommend' && $names===[]) { throw new \DomainException('Bitte mindestens einen gewünschten Referenten auswählen.'); }
            if ($mode==='recommend' && $names!==[]) { throw new \DomainException('Bitte entweder eine Empfehlung oder die gewünschten Personen wählen.'); }
            $details+=['seminar_title'=>$seminar['title'],'participants'=>$participants,'date'=>$date,'location'=>Catalog::text($values,'location',200),'format'=>Catalog::text($values,'format',40),'referent_mode'=>$mode,'referents'=>$names];
            if (!in_array($details['format'],['Corpshaus / Präsenz','Online','Noch offen'],true)) { throw new \DomainException('Bitte ein gültiges Seminarformat wählen.'); }
        }
        $this->limit('inquiry',$email,$ip);
        $this->db->immediate(function() use ($submission,$details,$email,$kind,$seminar): void {
            if ($this->store->submission($submission)!==null) { return; }
            if ($seminar!==null && $this->catalog->seminar($seminar['slug'])===null) { throw new \DomainException('Das Seminar ist inzwischen nicht mehr anfragbar.'); }
            $now=$this->clock->now()->getTimestamp();
            $id=$this->store->insertInquiry(['public_id'=>Crypto::token(),'submission_hash'=>$submission,'kind'=>$kind,'seminar_id'=>$seminar['id']??null,'email'=>$email,'email_key'=>Accounts::emailKey($email),'details_ciphertext'=>$this->crypto->encrypt(json_encode($details,JSON_THROW_ON_ERROR),'inquiry'),'key_id'=>$this->config->keyId,'state'=>'unverified','consent_version'=>self::CONSENT,'created_at'=>$now,'updated_at'=>$now]);
            $token=$this->store->issueToken('inquiry_verify',$id,null,$now);
            $link=$this->config->url.'/anfrage/bestaetigen#token='.$token['token'];
            $this->mail->enqueue('inquiry-verify:'.$token['id'],$email,'Bitte bestätigen Sie Ihre Anfrage',"Guten Tag,\n\nbitte bestätigen Sie Ihre E-Mail-Adresse, damit die Corpsakademie Ihre Anfrage bearbeiten kann:\n\n".$link."\n\nDieser Link gilt einmalig für 30 Minuten. Ihre Anfrage ist noch keine verbindliche Buchung. Falls Sie diese Nachricht nicht angefordert haben, ignorieren Sie sie.",$token['id']);
        });
    }

    public function confirmInquiry(string $token, string $ip): void
    {
        if (!$this->limits->attempt('public.consume.ip',$ip,20,900)) { throw new \DomainException('Bitte versuchen Sie es später erneut.'); }
        $this->db->immediate(function() use ($token): void {
            $now=$this->clock->now()->getTimestamp(); $row=$this->store->consume('inquiry_verify',$token,$now);
            $this->store->activateInquiry((int)$row['inquiry_id'],$now);
            $inquiry=$this->store->inquiryById((int)$row['inquiry_id']);
            $this->mail->enqueue('inquiry-received:'.$row['inquiry_id'],$inquiry['email'],'Ihre Anfrage ist eingegangen',"Vielen Dank. Ihre E-Mail-Adresse ist bestätigt und Ihre Anfrage liegt der Corpsakademie zur Prüfung vor.\n\nTermin, Referenten und Konditionen werden gesondert abgestimmt. Diese Nachricht ist keine verbindliche Buchungsbestätigung.");
            if ($this->config->mailContactTo!=='') {
                $this->mail->enqueue('inquiry-notify:'.$row['inquiry_id'],$this->config->mailContactTo,'Neue bestätigte Anfrage',"Eine neue bestätigte Anfrage liegt in der geschützten Verwaltung vor:\n\n".$this->config->url.'/admin/anfragen/'.$inquiry['public_id']);
            }
            $this->audit->record('inquiry.verified',null,$inquiry['public_id'],[],'inquiry');
        });
    }

    public function subscribe(array $values, string $ip): void
    {
        $email=$this->email($values); $this->consent($values); $this->limit('newsletter',$email,$ip);
        $this->db->immediate(function() use ($email): void {
            $existing=$this->store->subscriber(Accounts::emailKey($email));
            if ($existing!==null && $existing['state']==='active') { return; }
            $now=$this->clock->now()->getTimestamp();
            $id=$this->store->requestSubscriber($email,Accounts::emailKey($email),$now,self::CONSENT);
            $this->audit->record('newsletter.requested',null,(string)$id,['consent_version'=>self::CONSENT],'subscriber');
            $token=$this->store->issueToken('newsletter_confirm',null,$id,$now);
            $this->mail->enqueue('newsletter-confirm:'.$token['id'],$email,'Newsletter-Anmeldung bestätigen',"Bitte bestätigen Sie Ihre Anmeldung für Neuigkeiten der Corpsakademie:\n\n".$this->config->url.'/newsletter/bestaetigen#token='.$token['token']."\n\nEinmalig nutzbar und 30 Minuten gültig. Ohne Ihre Bestätigung erfolgt keine Anmeldung. Falls Sie diese Nachricht nicht angefordert haben, ignorieren Sie sie.",$token['id']);
        });
    }

    public function confirmNewsletter(string $token, string $ip): void
    {
        if (!$this->limits->attempt('public.consume.ip',$ip,20,900)) { throw new \DomainException('Bitte versuchen Sie es später erneut.'); }
        $this->db->immediate(function() use ($token): void {
            $now=$this->clock->now()->getTimestamp(); $row=$this->store->consume('newsletter_confirm',$token,$now);
            $id=(int)$row['subscriber_id']; $this->store->confirmSubscriber($id,$now);
            $unsubscribe=$this->store->issueToken('newsletter_unsubscribe',null,$id,$now,365*86400);
            $subscriber=$this->store->subscriberById($id);
            $this->audit->record('newsletter.confirmed',null,(string)$id,['consent_version'=>$subscriber['consent_version']],'subscriber');
            $this->mail->enqueue('newsletter-welcome:'.$row['id'],$subscriber['email'],'Ihre Newsletter-Anmeldung ist bestätigt',"Ihre Anmeldung für Neuigkeiten der Corpsakademie ist bestätigt.\n\nSie können sich hier abmelden:\n\n".$this->config->url.'/newsletter/abmelden#token='.$unsubscribe['token'],$unsubscribe['id']);
        });
    }

    public function unsubscribe(string $token, string $ip): void
    {
        if (!$this->limits->attempt('public.consume.ip',$ip,20,900)) { throw new \DomainException('Bitte versuchen Sie es später erneut.'); }
        $this->db->immediate(function() use ($token): void {
            $now=$this->clock->now()->getTimestamp();
            if (!preg_match('/^[A-Za-z0-9_-]{43}$/D',$token)) { throw new \DomainException('Dieser Link ist ungültig.'); }
            $row=$this->db->one("SELECT subscriber_id FROM newsletter_deliveries WHERE unsubscribe_hash=? AND state IN ('sending','submitted','ambiguous')",[Crypto::hash($token)]);
            $row??=$this->store->consume('newsletter_unsubscribe',$token,$now);
            $this->store->unsubscribe((int)$row['subscriber_id'],$now);
            $this->audit->record('newsletter.unsubscribed',null,(string)$row['subscriber_id'],[],'subscriber');
        });
    }

    public function listForAdmin(int $actor): array
    {
        $this->authorize($actor);
        return array_map($this->decryptInquiry(...),$this->store->inquiries());
    }

    public function detailForAdmin(int $actor, string $id): ?array
    {
        $this->authorize($actor); $row=$this->store->inquiry($id);
        return $row===null || $row['state']==='unverified'?null:$this->decryptInquiry($row);
    }

    public function updateState(int $actor, string $id, string $state): void
    {
        $this->db->immediate(function() use ($actor,$id,$state): void {
            $this->authorize($actor); $row=$this->store->inquiry($id);
            if ($row===null || $row['state']==='unverified' || !in_array($state,['received','in_progress','closed'],true)) { throw new \DomainException('Ungültiger Bearbeitungsstatus.'); }
            $this->store->updateState((int)$row['id'],$state,$this->clock->now()->getTimestamp());
            $this->audit->record('inquiry.state_changed',$actor,$id,['state'=>$state],'inquiry');
        });
    }

    private function decryptInquiry(array $row): array
    {
        $row['details']=json_decode($this->crypto->decrypt($row['details_ciphertext'],'inquiry',$row['key_id']),true,512,JSON_THROW_ON_ERROR);
        unset($row['details_ciphertext'],$row['key_id'],$row['submission_hash'],$row['email_key']);
        return $row;
    }

    private function authorize(int $actor): void
    {
        if (!$this->accounts->can($actor,'inquiries.manage')) { throw new \DomainException('Für die Anfragenverwaltung fehlt die Berechtigung.'); }
    }

    private function email(array $values): string
    {
        $email=Catalog::text($values,'email',254);
        if (filter_var($email,FILTER_VALIDATE_EMAIL)===false) { throw new \DomainException('Bitte eine gültige E-Mail-Adresse eingeben.'); }
        if ($this->config->environment!=='production' && !str_ends_with(mb_strtolower($email),'@example.test')) { throw new \DomainException('Für diese Vorschau bitte eine Testadresse mit @example.test verwenden. Die Nachrichten erscheinen im lokalen Testpostfach.'); }
        return $email;
    }

    private function consent(array $values): void
    {
        if (($values['consent']??'')!=='1') { throw new \DomainException('Bitte den Datenschutzhinweis bestätigen.'); }
        if (($values['website']??'')!=='') { throw new \DomainException('Die Anfrage konnte nicht verarbeitet werden.'); }
    }

    private function limit(string $scope, string $email, string $ip): void
    {
        $a=$this->limits->attempt('public.'.$scope.'.ip',$ip,10,3600);
        $b=$this->limits->attempt('public.'.$scope.'.email',Accounts::emailKey($email),3,3600);
        if (!$a || !$b) { throw new \DomainException('Zu viele Anfragen. Bitte versuchen Sie es in einer Stunde erneut.'); }
    }
}
