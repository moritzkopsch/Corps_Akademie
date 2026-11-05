<?php
declare(strict_types=1);
namespace Corpsakademie\Application;

use Corpsakademie\Domain\Identity\Accounts;
use Corpsakademie\Infrastructure\{Audit,CatalogRepository,Database};
use Psr\Clock\ClockInterface;

final class Catalog
{
    /** Auswahlmöglichkeiten für die Dauer eines Seminars. */
    public const DURATION_OPTIONS = ['1 Tag','2 Tage','3 Tage','4 Tage','5 Tage oder mehr','Nach Absprache'];

    /** So viele Seminare darf die Startseite höchstens hervorheben. */
    public const FEATURED_LIMIT = 6;

    public function __construct(private readonly Database $db, private readonly CatalogRepository $store, private readonly Accounts $accounts, private readonly Audit $audit, private readonly ClockInterface $clock) {}

    public function saveSeminar(int $actor, array $values, ?string $existingSlug = null): string
    {
        $this->authorize($actor);
        $data=[];
        foreach (['slug'=>100,'title'=>140,'summary'=>350,'description'=>6000,'audience'=>250,'format'=>100] as $key=>$max) { $data[$key]=self::text($values,$key,$max); }
        $data['benefits']=self::lines($values,'benefits');
        $data['status']=self::status($values); $data['category_id']=(int)($values['category_id']??0);
        $data['featured']=($values['featured']??'')==='1'?1:0;
        $data['duration_options']=self::durationOptions($values);
        $data['duration']=implode(' / ',$data['duration_options']);
        $data['image_path']=self::mediaPath($values['image_path']??'');
        $data['row_version']=(int)($values['row_version']??0);
        self::slug($data['slug']);
        if (!in_array($data['category_id'],array_column($this->store->categories(),'id'),true)) { throw new \DomainException('Bitte eine gültige Kategorie wählen.'); }
        $ids=$values['referents']??[];
        if (!is_array($ids) || count($ids)>30) { throw new \DomainException('Bitte die Referentenauswahl prüfen.'); }
        $ids=array_values(array_unique(array_map(static fn($v): int=>is_scalar($v)?(int)$v:0,$ids)));
        $allowed=array_column($this->store->referents(false),'id');
        foreach ($ids as $id) { if (!in_array($id,$allowed,true)) { throw new \DomainException('Ein ausgewähltes Referentenprofil ist ungültig.'); } }
        return $this->db->immediate(function() use ($actor,$data,$ids,$existingSlug): string {
            $this->authorize($actor);
            $existing=$existingSlug===null?null:$this->store->seminar($existingSlug,false);
            if ($existingSlug!==null && $existing===null) { throw new \DomainException('Seminar nicht gefunden.'); }
            $duplicate=$this->store->seminar($data['slug'],false);
            if ($duplicate!==null && ($existing===null || $duplicate['id']!==$existing['id'])) { throw new \DomainException('Diese Seitenadresse ist bereits vergeben.'); }
            if ($data['featured']===1 && $this->store->featuredCount($existing===null?null:(int)$existing['id'])>=self::FEATURED_LIMIT) {
                throw new \DomainException('Es sind bereits '.self::FEATURED_LIMIT.' Seminare hervorgehoben. Bitte zuerst eine Hervorhebung entfernen.');
            }
            $this->store->saveSeminar($data,$existing,$ids,$this->clock->now()->getTimestamp());
            $this->audit->record('content.seminar_saved',$actor,$data['slug'],['status'=>$data['status']],'seminar');
            return $data['slug'];
        });
    }

    public function saveReferent(int $actor, array $values, ?string $existingSlug = null): string
    {
        $this->authorize($actor); $data=[];
        foreach (['slug'=>100,'name'=>140,'headline'=>180,'biography'=>6000] as $key=>$max) { $data[$key]=self::text($values,$key,$max); }
        $data['corps_memberships']=self::bullets($values,'corps_memberships',10);
        // Die Textspalte corps bleibt für Altdaten erhalten und wird aus den Mitgliedschaften abgeleitet.
        $data['corps']=$data['corps_memberships']===[]?self::text($values,'corps',250,false):implode(' · ',$data['corps_memberships']);
        $data['career']=self::bullets($values,'career',12);
        $data['user_id']=self::link($values['user_id']??'');
        $data['expertise']=self::lines($values,'expertise'); $data['status']=self::status($values);
        $data['linkedin_url']=self::linkedin($values['linkedin_url']??'');
        $data['image_path']=self::mediaPath($values['image_path']??'');
        $data['cv_path']=self::mediaPath($values['cv_path']??'');
        $data['row_version']=(int)($values['row_version']??0); self::slug($data['slug']);
        return $this->db->immediate(function() use ($actor,$data,$existingSlug): string {
            $this->authorize($actor);
            $existing=$existingSlug===null?null:$this->store->referent($existingSlug,false);
            if ($existingSlug!==null && $existing===null) { throw new \DomainException('Profil nicht gefunden.'); }
            $duplicate=$this->store->referent($data['slug'],false);
            if ($duplicate!==null && ($existing===null || $duplicate['id']!==$existing['id'])) { throw new \DomainException('Diese Seitenadresse ist bereits vergeben.'); }
            if ($data['user_id']!==null) {
                if ($this->db->one('SELECT id FROM users WHERE id=?',[$data['user_id']])===null) { throw new \DomainException('Das gewählte Konto ist nicht vorhanden.'); }
                $taken=$this->db->one('SELECT id FROM referent_profiles WHERE user_id=?',[$data['user_id']]);
                if ($taken!==null && ($existing===null || (int)$taken['id']!==(int)$existing['id'])) { throw new \DomainException('Dieses Konto ist bereits mit einem Referentenprofil verknüpft.'); }
            }
            $this->store->saveReferent($data,$existing,$this->clock->now()->getTimestamp());
            $this->audit->record('content.referent_saved',$actor,$data['slug'],['status'=>$data['status']],'referent');
            return $data['slug'];
        });
    }

    public static function text(array $values, string $key, int $max, bool $required = true): string
    {
        $value=$values[$key]??'';
        if (!is_string($value)) { throw new \DomainException('Bitte die Formulareingaben prüfen.'); }
        $value=trim($value);
        if (($required && $value==='') || mb_strlen($value)>$max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)) { throw new \DomainException('Bitte das Feld „'.$key.'“ ausfüllen und die zulässige Länge beachten.'); }
        return $value;
    }

    private static function durationOptions(array $values): array
    {
        $selected=$values['duration_options']??[];
        if (!is_array($selected) || count($selected)>count(self::DURATION_OPTIONS)) { throw new \DomainException('Bitte die Dauerauswahl prüfen.'); }
        $options=[];
        foreach (self::DURATION_OPTIONS as $option) { if (in_array($option,$selected,true)) { $options[]=$option; } }
        if ($options===[]) { throw new \DomainException('Bitte mindestens eine Dauer auswählen.'); }
        return $options;
    }

    /** Akzeptiert ausschließlich Pfade, die der Upload-Ablage entsprechen. */
    public static function mediaPath(mixed $value): string
    {
        if ($value===null || $value==='') { return ''; }
        if (!is_string($value) || !preg_match('~^uploads/[a-z0-9-]+/[a-f0-9]{24}\.(?:jpg|png|webp|pdf)$~D',$value)) { throw new \DomainException('Die hochgeladene Datei ist ungültig.'); }
        return $value;
    }

    private static function lines(array $values, string $key): array
    {
        $lines=array_values(array_filter(array_map('trim',explode("\n",self::text($values,$key,2000)))));
        if (count($lines)<1 || count($lines)>12) { throw new \DomainException('Bitte zwischen 1 und 12 Stichpunkte eintragen.'); }
        foreach ($lines as $line) { if (mb_strlen($line)>250) { throw new \DomainException('Ein Stichpunkt ist zu lang.'); } }
        return $lines;
    }

    /** Optionale Stichpunktliste aus einem mehrzeiligen Textfeld. */
    private static function bullets(array $values, string $key, int $max): array
    {
        $value=$values[$key]??'';
        if (!is_string($value) || mb_strlen($value)>2000) { throw new \DomainException('Bitte das Feld „'.$key.'“ prüfen.'); }
        $lines=array_values(array_filter(array_map('trim',explode("\n",$value))));
        if (count($lines)>$max) { throw new \DomainException('Bitte höchstens '.$max.' Stichpunkte eintragen.'); }
        foreach ($lines as $line) { if (mb_strlen($line)>250) { throw new \DomainException('Ein Stichpunkt ist zu lang.'); } }
        return $lines;
    }

    /** Liest die optionale Kontoverknüpfung; leer bedeutet „kein Konto“. */
    private static function link(mixed $value): ?int
    {
        $value=is_scalar($value)?trim((string)$value):'';
        if ($value==='') { return null; }
        if (filter_var($value,FILTER_VALIDATE_INT)===false || (int)$value<1) { throw new \DomainException('Bitte eine gültige Kontoverknüpfung wählen.'); }
        return (int)$value;
    }

    /** Erlaubt ausschließlich vollständige LinkedIn-Profiladressen über HTTPS. */
    public static function linkedin(mixed $value): string
    {
        $value=is_string($value)?trim($value):'';
        if ($value==='') { return ''; }
        $host=is_string(parse_url($value,PHP_URL_HOST))?strtolower((string)parse_url($value,PHP_URL_HOST)):'';
        if (mb_strlen($value)>300 || filter_var($value,FILTER_VALIDATE_URL)===false || parse_url($value,PHP_URL_SCHEME)!=='https' || ($host!=='linkedin.com' && !str_ends_with($host,'.linkedin.com'))) {
            throw new \DomainException('Bitte eine LinkedIn-Adresse wie https://www.linkedin.com/in/name angeben.');
        }
        return $value;
    }

    private static function slug(string $slug): void
    {
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || $slug==='neu') { throw new \DomainException('Die Seitenadresse darf Kleinbuchstaben, Zahlen und Bindestriche enthalten.'); }
    }

    private static function status(array $values): string
    {
        $status=$values['status']??'';
        if (!is_string($status) || !in_array($status,['draft','published','inactive','archived'],true)) { throw new \DomainException('Bitte einen gültigen Veröffentlichungsstatus wählen.'); }
        return $status;
    }

    private function authorize(int $actor): void
    {
        if (!$this->accounts->can($actor,'content.manage')) { throw new \DomainException('Für diese Änderung fehlt die Berechtigung.'); }
    }
}
