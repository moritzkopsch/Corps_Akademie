<?php
declare(strict_types=1);
namespace Corpsakademie\Application;

use Corpsakademie\Domain\Identity\Accounts;
use Corpsakademie\Infrastructure\{Audit,CatalogRepository,Database};
use Psr\Clock\ClockInterface;

final class Catalog
{
    public function __construct(private readonly Database $db, private readonly CatalogRepository $store, private readonly Accounts $accounts, private readonly Audit $audit, private readonly ClockInterface $clock) {}

    public function saveSeminar(int $actor, array $values, ?string $existingSlug = null): string
    {
        $this->authorize($actor);
        $data=[];
        foreach (['slug'=>100,'title'=>140,'summary'=>350,'description'=>6000,'audience'=>250,'duration'=>80,'format'=>100] as $key=>$max) { $data[$key]=self::text($values,$key,$max); }
        $data['benefits']=self::lines($values,'benefits');
        $data['status']=self::status($values); $data['category_id']=(int)($values['category_id']??0);
        $data['featured']=($values['featured']??'')==='1'?1:0;
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
            $this->store->saveSeminar($data,$existing,$ids,$this->clock->now()->getTimestamp());
            $this->audit->record('content.seminar_saved',$actor,$data['slug'],['status'=>$data['status']],'seminar');
            return $data['slug'];
        });
    }

    public function saveReferent(int $actor, array $values, ?string $existingSlug = null): string
    {
        $this->authorize($actor); $data=[];
        foreach (['slug'=>100,'name'=>140,'headline'=>180,'biography'=>6000] as $key=>$max) { $data[$key]=self::text($values,$key,$max); }
        $data['corps']=self::text($values,'corps',250,false);
        $data['expertise']=self::lines($values,'expertise'); $data['status']=self::status($values);
        $data['row_version']=(int)($values['row_version']??0); self::slug($data['slug']);
        return $this->db->immediate(function() use ($actor,$data,$existingSlug): string {
            $this->authorize($actor);
            $existing=$existingSlug===null?null:$this->store->referent($existingSlug,false);
            if ($existingSlug!==null && $existing===null) { throw new \DomainException('Profil nicht gefunden.'); }
            $duplicate=$this->store->referent($data['slug'],false);
            if ($duplicate!==null && ($existing===null || $duplicate['id']!==$existing['id'])) { throw new \DomainException('Diese Seitenadresse ist bereits vergeben.'); }
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

    private static function lines(array $values, string $key): array
    {
        $lines=array_values(array_filter(array_map('trim',explode("\n",self::text($values,$key,2000)))));
        if (count($lines)<1 || count($lines)>12) { throw new \DomainException('Bitte zwischen 1 und 12 Stichpunkte eintragen.'); }
        foreach ($lines as $line) { if (mb_strlen($line)>250) { throw new \DomainException('Ein Stichpunkt ist zu lang.'); } }
        return $lines;
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
