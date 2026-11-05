<?php
declare(strict_types=1);
namespace Corpsakademie\Application;

use Corpsakademie\Domain\Identity\Accounts;
use Corpsakademie\Infrastructure\{Audit,Config,Crypto,Database};
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class Calendar
{
    /** So viele Monate im Voraus müssen Referenten planbar bleiben. */
    public const PLANNING_MONTHS = 6;
    /** Höchstens so viele Verfügbarkeitszeiträume je Referent. */
    public const MAX_WINDOWS = 60;
    /** Höchstens so viele offene oder bestätigte Termine je Referent. */
    public const MAX_APPOINTMENTS = 100;
    /** Anzeige der Terminzustände. */
    public const STATES = ['proposed'=>'Vorgeschlagen','confirmed'=>'Bestätigt','declined'=>'Abgelehnt','cancelled'=>'Zurückgezogen'];
    /** Verfügbarkeit kennt nur freie und gesperrte Zeiträume. */
    public const KINDS = ['available'=>'Verfügbar','blocked'=>'Gesperrt'];

    private const MONTHS = ['','Januar','Februar','März','April','Mai','Juni','Juli','August','September','Oktober','November','Dezember'];

    public function __construct(private readonly Database $db, private readonly Config $config, private readonly Accounts $accounts, private readonly Audit $audit, private readonly ClockInterface $clock, private readonly MailerInterface $mailer) {}

    /** Ordnet das angemeldete Konto seinem Referentenprofil zu. */
    public function profileForUser(int $userId): ?array
    {
        $row=$this->db->one('SELECT id,name,slug,status FROM referent_profiles WHERE user_id=?',[$userId]);
        return $row===null?null:['id'=>(int)$row['id'],'name'=>$row['name'],'slug'=>$row['slug'],'status'=>$row['status']];
    }

    public function windows(int $referentId): array
    {
        return $this->db->run('SELECT id,starts_on,ends_on,kind,note FROM referent_availability WHERE referent_id=? ORDER BY starts_on DESC,id DESC LIMIT 200',[$referentId])->fetchAll();
    }

    public function appointments(int $referentId): array
    {
        return $this->appointmentRows('a.referent_id=?',[$referentId]);
    }

    /** Offene Vorschläge, abgeschlossene Termine und die Planungslage aller Referenten. */
    public function board(int $actor): array
    {
        $this->authorizeManager($actor);
        return [
            'pending'=>$this->appointmentRows("a.state='proposed'",[]),
            'decided'=>$this->appointmentRows("a.state<>'proposed'",[],'a.day DESC,a.id DESC'),
            'referents'=>$this->referentOverview(),
        ];
    }

    public function detail(int $actor,int $referentId): ?array
    {
        $this->authorizeManager($actor);
        $row=$this->db->one('SELECT r.id,r.name,r.slug,r.status,r.user_id,u.email FROM referent_profiles r LEFT JOIN users u ON u.id=r.user_id WHERE r.id=?',[$referentId]);
        if ($row===null) { return null; }
        return [
            'id'=>(int)$row['id'],'name'=>$row['name'],'slug'=>$row['slug'],'status'=>$row['status'],'email'=>$row['email'],'linked'=>$row['user_id']!==null,
            'windows'=>$this->windows($referentId),'appointments'=>$this->appointments($referentId),'coverage'=>$this->coverage($referentId),
        ];
    }

    /** Monatsübersicht der nächsten Monate mit der Angabe, ob dort etwas geplant ist. */
    public function coverage(int $referentId): array
    {
        $from=$this->monthStart()->format('Y-m-d');
        $to=$this->monthStart(self::PLANNING_MONTHS-1)->modify('last day of this month')->format('Y-m-d');
        $windows=$this->db->run('SELECT starts_on,ends_on FROM referent_availability WHERE referent_id=? AND ends_on>=? AND starts_on<=?',[$referentId,$from,$to])->fetchAll();
        $appointments=$this->db->run("SELECT day FROM referent_appointments WHERE referent_id=? AND state IN ('proposed','confirmed') AND day>=? AND day<=?",[$referentId,$from,$to])->fetchAll();
        $months=[];
        for ($i=0;$i<self::PLANNING_MONTHS;$i++) {
            $month=$this->monthStart($i);
            $first=$month->format('Y-m-d'); $last=$month->modify('last day of this month')->format('Y-m-d');
            $planned=false;
            foreach ($windows as $window) { if ($window['starts_on']<=$last && $window['ends_on']>=$first) { $planned=true; break; } }
            if (!$planned) { foreach ($appointments as $appointment) { if ($appointment['day']>=$first && $appointment['day']<=$last) { $planned=true; break; } } }
            $months[]=['key'=>$month->format('Y-m'),'label'=>self::MONTHS[(int)$month->format('n')].' '.$month->format('Y'),'planned'=>$planned];
        }
        return $months;
    }

    /** Speichert einen Verfügbarkeitszeitraum des eigenen Kalenders. */
    public function saveWindow(int $actor,array $values): void
    {
        $profile=$this->own($actor);
        $starts=self::date($values,'starts_on'); $ends=self::date($values,'ends_on');
        if ($ends<$starts) { throw new \DomainException('Das Ende darf nicht vor dem Beginn liegen.'); }
        $kind=is_string($values['kind']??null)?$values['kind']:'';
        if (!isset(self::KINDS[$kind])) { throw new \DomainException('Bitte die Art des Zeitraums wählen.'); }
        $note=self::text($values,'note',300,'Notiz');
        $this->db->immediate(function() use ($actor,$profile,$starts,$ends,$kind,$note): void {
            $count=(int)$this->db->run('SELECT COUNT(*) FROM referent_availability WHERE referent_id=?',[$profile['id']])->fetchColumn();
            if ($count>=self::MAX_WINDOWS) { throw new \DomainException('Es sind höchstens '.self::MAX_WINDOWS.' Zeiträume je Referent möglich.'); }
            $now=$this->clock->now()->getTimestamp();
            $this->db->run('INSERT INTO referent_availability(referent_id,starts_on,ends_on,kind,note,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)',[$profile['id'],$starts,$ends,$kind,$note,$actor,$now,$now]);
            $this->audit->record('calendar.window_saved',$actor,(string)$profile['id'],['from'=>$starts,'to'=>$ends,'kind'=>$kind],'referent');
        });
    }

    public function removeWindow(int $actor,int $windowId): void
    {
        $profile=$this->own($actor);
        $this->db->immediate(function() use ($actor,$profile,$windowId): void {
            if ($this->db->run('DELETE FROM referent_availability WHERE id=? AND referent_id=?',[$windowId,$profile['id']])->rowCount()!==1) {
                throw new \DomainException('Dieser Zeitraum ist nicht vorhanden.');
            }
            $this->audit->record('calendar.window_removed',$actor,(string)$profile['id'],['window'=>(string)$windowId],'referent');
        });
    }

    /** Ein Referent schlägt einen Termin vor; bestätigen muss ihn die Verwaltung. */
    public function propose(int $actor,array $values): void
    {
        $this->create($actor,$this->own($actor),$values,'referent','proposed');
    }

    /** Die Verwaltung trägt einen bereits abgestimmten Termin ein. */
    public function plan(int $actor,int $referentId,array $values): void
    {
        $this->authorizeManager($actor);
        $profile=$this->db->one('SELECT id FROM referent_profiles WHERE id=?',[$referentId]);
        if ($profile===null) { throw new \DomainException('Dieser Referent ist nicht vorhanden.'); }
        $this->create($actor,['id'=>(int)$profile['id']],$values,'admin','confirmed');
    }

    /** Ein Referent zieht einen eigenen, noch nicht bestätigten Vorschlag zurück. */
    public function withdraw(int $actor,int $appointmentId): void
    {
        $profile=$this->own($actor);
        $this->db->immediate(function() use ($actor,$profile,$appointmentId): void {
            $row=$this->db->one('SELECT state,source FROM referent_appointments WHERE id=? AND referent_id=?',[$appointmentId,$profile['id']]);
            if ($row===null) { throw new \DomainException('Dieser Termin ist nicht vorhanden.'); }
            if ($row['source']!=='referent' || !in_array($row['state'],['proposed','declined'],true)) {
                throw new \DomainException('Dieser Termin kann nur von der Verwaltung geändert werden.');
            }
            $this->db->run("UPDATE referent_appointments SET state='cancelled',updated_at=? WHERE id=?",[$this->clock->now()->getTimestamp(),$appointmentId]);
            $this->audit->record('calendar.appointment_withdrawn',$actor,(string)$profile['id'],['appointment'=>(string)$appointmentId],'referent');
        });
    }

    public function decide(int $actor,int $appointmentId,string $state): void
    {
        if (!in_array($state,['confirmed','declined'],true)) { throw new \DomainException('Bitte eine gültige Entscheidung wählen.'); }
        $this->db->immediate(function() use ($actor,$appointmentId,$state): void {
            $this->authorizeManager($actor);
            $row=$this->db->one('SELECT referent_id,state FROM referent_appointments WHERE id=?',[$appointmentId]);
            if ($row===null) { throw new \DomainException('Dieser Termin ist nicht vorhanden.'); }
            if (!in_array($row['state'],['proposed','confirmed','declined'],true)) { throw new \DomainException('Dieser Termin ist bereits abgeschlossen.'); }
            $now=$this->clock->now()->getTimestamp();
            $this->db->run('UPDATE referent_appointments SET state=?,decided_by=?,decided_at=?,updated_at=? WHERE id=?',[$state,$actor,$now,$now,$appointmentId]);
            $this->audit->record('calendar.appointment_decided',$actor,(string)$row['referent_id'],['state'=>$state,'appointment'=>(string)$appointmentId],'referent');
        });
    }

    /**
     * Erinnert Referenten höchstens einmal je Monat, solange die nächsten Monate ungeplant sind.
     * Der Ereignisschlüssel verhindert doppelte Nachrichten; ein SMTP-Fehler wird nicht wiederholt.
     */
    public function remind(int $limit=20): array
    {
        if (!$this->config->mailEnabled) { throw new \DomainException('Der E-Mail-Versand ist noch nicht eingerichtet.'); }
        $results=['submitted'=>0,'blocked'=>0,'ambiguous'=>0];
        for ($i=0;$i<$limit;$i++) {
            $target=$this->db->immediate(fn(): ?array=>$this->claimReminder());
            if ($target===null) { break; }
            if ($this->config->environment!=='production' && !str_ends_with(mb_strtolower($target['email']),'@example.test')) {
                $this->db->run("UPDATE calendar_reminders SET status='blocked',last_error_redacted='Versand in dieser Umgebung unterbunden' WHERE id=?",[$target['id']]);
                $results['blocked']++; continue;
            }
            try {
                $this->mailer->send($this->reminder($target));
                $this->db->run("UPDATE calendar_reminders SET status='submitted',submitted_at=? WHERE id=? AND status='queued'",[$this->clock->now()->getTimestamp(),$target['id']]);
                $results['submitted']++;
            } catch (\Throwable) {
                // Der Inhalt wird nicht protokolliert; ein zweiter Versuch könnte doppelt zustellen.
                $this->db->run("UPDATE calendar_reminders SET status='ambiguous',last_error_redacted='SMTP-Fehler' WHERE id=? AND status='queued'",[$target['id']]);
                $results['ambiguous']++;
            }
        }
        $now=$this->clock->now()->getTimestamp();
        $this->db->run('INSERT INTO worker_heartbeats(worker_name,last_started_at,last_success_at,last_error_at,release_version) VALUES(?,?,?,?,?) ON CONFLICT(worker_name) DO UPDATE SET last_started_at=excluded.last_started_at,last_success_at=excluded.last_success_at,last_error_at=excluded.last_error_at,release_version=excluded.release_version',[
            'calendar-reminder',$now,$results['ambiguous']===0?$now:null,$results['ambiguous']>0?$now:null,'0.1.0',
        ]);
        return $results;
    }

    public function today(): string { return $this->now()->format('Y-m-d'); }

    private function create(int $actor,array $profile,array $values,string $source,string $state): void
    {
        $title=self::text($values,'title',140,'Bezeichnung');
        if ($title==='') { throw new \DomainException('Bitte eine Bezeichnung für den Termin angeben.'); }
        $day=self::date($values,'day');
        $location=self::text($values,'location',120,'Ort');
        $note=self::text($values,'note',300,'Notiz');
        $seminarId=is_numeric($values['seminar_id']??null)?(int)$values['seminar_id']:0;
        if ($seminarId>0 && $this->db->one('SELECT id FROM seminars WHERE id=?',[$seminarId])===null) { throw new \DomainException('Das gewählte Seminar ist nicht vorhanden.'); }
        $this->db->immediate(function() use ($actor,$profile,$title,$day,$location,$note,$seminarId,$source,$state): void {
            $open=(int)$this->db->run("SELECT COUNT(*) FROM referent_appointments WHERE referent_id=? AND state IN ('proposed','confirmed')",[$profile['id']])->fetchColumn();
            if ($open>=self::MAX_APPOINTMENTS) { throw new \DomainException('Es sind höchstens '.self::MAX_APPOINTMENTS.' offene oder bestätigte Termine je Referent möglich.'); }
            $now=$this->clock->now()->getTimestamp();
            $this->db->run('INSERT INTO referent_appointments(referent_id,seminar_id,title,location,day,state,source,note,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[
                $profile['id'],$seminarId>0?$seminarId:null,$title,$location,$day,$state,$source,$note,$actor,$now,$now,
            ]);
            $this->audit->record('calendar.appointment_created',$actor,(string)$profile['id'],['state'=>$state,'day'=>$day],'referent');
        });
    }

    private function appointmentRows(string $condition,array $arguments,string $order='a.day ASC,a.id ASC'): array
    {
        return $this->db->run('SELECT a.id,a.referent_id,a.seminar_id,a.title,a.location,a.day,a.state,a.source,a.note,a.decided_at,r.name AS referent,s.title AS seminar FROM referent_appointments a JOIN referent_profiles r ON r.id=a.referent_id LEFT JOIN seminars s ON s.id=a.seminar_id WHERE '.$condition.' ORDER BY '.$order.' LIMIT 200',$arguments)->fetchAll();
    }

    /** Planungslage aller Referenten für die Verwaltungsübersicht. */
    private function referentOverview(): array
    {
        $referents=$this->db->run('SELECT r.id,r.name,r.slug,r.status,r.user_id,u.email FROM referent_profiles r LEFT JOIN users u ON u.id=r.user_id ORDER BY r.name LIMIT 200')->fetchAll();
        return array_map(function(array $referent): array {
            $coverage=$this->coverage((int)$referent['id']);
            $missing=array_values(array_filter($coverage,static fn(array $month): bool=>!$month['planned']));
            return ['id'=>(int)$referent['id'],'name'=>$referent['name'],'slug'=>$referent['slug'],'status'=>$referent['status'],'email'=>$referent['email'],'linked'=>$referent['user_id']!==null,
                'planned'=>count($coverage)-count($missing),'missing'=>array_column($missing,'label')];
        },$referents);
    }

    /** Sucht einen Referenten mit ungeplanter Zukunft, der in diesem Monat noch keine Erinnerung erhielt. */
    private function claimReminder(): ?array
    {
        $period=$this->now()->format('Y-m');
        $referents=$this->db->run("SELECT r.id,r.name,u.email FROM referent_profiles r JOIN users u ON u.id=r.user_id WHERE r.user_id IS NOT NULL AND r.status='published' AND u.status='active' ORDER BY r.id")->fetchAll();
        foreach ($referents as $referent) {
            $months=array_values(array_filter($this->coverage((int)$referent['id']),static fn(array $month): bool=>!$month['planned']));
            if ($months===[]) { continue; }
            $eventKey='calendar_reminder:'.(int)$referent['id'].':'.$period;
            if ($this->db->one('SELECT id FROM calendar_reminders WHERE event_key=?',[$eventKey])!==null) { continue; }
            $id=(int)$this->db->run('INSERT INTO calendar_reminders(event_key,referent_id,period,recipient,status,message_id,created_at) VALUES(?,?,?,?,?,?,?) RETURNING id',[
                $eventKey,(int)$referent['id'],$period,$referent['email'],'queued',Crypto::token().'@'.parse_url($this->config->url,PHP_URL_HOST),$this->clock->now()->getTimestamp(),
            ])->fetchColumn();
            return ['id'=>$id,'name'=>$referent['name'],'email'=>$referent['email'],'missing'=>array_column($months,'label')];
        }
        return null;
    }

    private function reminder(array $target): Email
    {
        $url=$this->config->url.'/portal/kalender';
        $missing=implode(', ',$target['missing']);
        $safe=static fn(string $value): string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $text='Hallo '.$target['name'].",\n\nfür die nächsten ".self::PLANNING_MONTHS.' Monate ist dein Kalender noch nicht vollständig geplant. Ohne Eintrag: '.$missing.".\n\nBitte trage dort deine Verfügbarkeit und deine Termine ein:\n".$url."\n\nCorpsakademie";
        return (new Email())->from($this->config->mailFrom)->replyTo($this->config->mailReplyTo)->to($target['email'])->subject('Corpsakademie · Kalender planen')
            ->text($text)
            ->html('<html lang="de"><body><p>Hallo '.$safe($target['name']).',</p><p>für die nächsten '.self::PLANNING_MONTHS.' Monate ist dein Kalender noch nicht vollständig geplant. Ohne Eintrag: '.$safe($missing).'.</p><p><a href="'.$safe($url).'">Kalender öffnen</a></p><p>Corpsakademie</p></body></html>');
    }

    /** Die Pflege des eigenen Kalenders setzt ein verknüpftes Referentenprofil voraus. */
    private function own(int $actor): array
    {
        if (!$this->accounts->can($actor,'portal.access')) { throw new \DomainException('Für den eigenen Kalender fehlt die Berechtigung.'); }
        $profile=$this->profileForUser($actor);
        if ($profile===null) { throw new \DomainException('Dein Konto ist noch keinem Referentenprofil zugeordnet. Bitte wende dich an die Verwaltung.'); }
        return $profile;
    }

    private function authorizeManager(int $actor): void
    {
        if (!$this->accounts->can($actor,'calendar.manage')) { throw new \DomainException('Für diese Änderung fehlt die Berechtigung.'); }
    }

    private function now(): \DateTimeImmutable { return $this->clock->now()->setTimezone(new \DateTimeZone('Europe/Berlin')); }

    private function monthStart(int $offset=0): \DateTimeImmutable
    {
        $start=$this->now()->modify('first day of this month')->setTime(0,0);
        return $offset===0?$start:$start->modify('+'.$offset.' month');
    }

    /** Akzeptiert ausschließlich echte Kalenderdaten in der Schreibweise der Formulare. */
    private static function date(array $values,string $key): string
    {
        $value=$values[$key]??'';
        $date=is_string($value)?\DateTimeImmutable::createFromFormat('!Y-m-d',$value):false;
        if ($date===false || $date->format('Y-m-d')!==$value) { throw new \DomainException('Bitte ein gültiges Datum im Format Jahr-Monat-Tag wählen.'); }
        $year=(int)$date->format('Y');
        if ($year<2000 || $year>2100) { throw new \DomainException('Bitte ein Datum zwischen 2000 und 2100 wählen.'); }
        return $value;
    }

    private static function text(array $values,string $key,int $max,string $label): string
    {
        $value=$values[$key]??'';
        $value=is_string($value)?trim($value):'';
        if (mb_strlen($value)>$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)) { throw new \DomainException('Bitte das Feld „'.$label.'“ prüfen.'); }
        return $value;
    }
}
