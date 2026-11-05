<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

final class CatalogRepository
{
    public function __construct(private readonly Database $db) {}

    public function categories(): array
    {
        return array_map(static function (array $row): array {
            $row['id']=(int)$row['id']; $row['position']=(int)$row['position'];
            return $row;
        },$this->db->run('SELECT * FROM seminar_categories ORDER BY position')->fetchAll());
    }

    public function seminars(bool $public = true, string $category = '', string $search = ''): array
    {
        $sql='SELECT s.*,c.name AS category,c.slug AS category_slug FROM seminars s JOIN seminar_categories c ON c.id=s.category_id WHERE 1=1';
        $args=[];
        if ($public) { $sql.=" AND s.status='published'"; }
        if ($category!=='') { $sql.=' AND c.slug=?'; $args[]=$category; }
        if ($search!=='') { $sql.=" AND (s.title LIKE ? ESCAPE '\\' OR s.summary LIKE ? ESCAPE '\\')"; $term='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$search).'%'; $args[]=$term; $args[]=$term; }
        return array_map($this->seminarProjection(...),$this->db->run($sql.' ORDER BY s.featured DESC,c.position,s.id LIMIT 200',$args)->fetchAll());
    }

    public function seminar(string $slug, bool $public = true): ?array
    {
        $row=$this->db->one('SELECT s.*,c.name AS category,c.slug AS category_slug FROM seminars s JOIN seminar_categories c ON c.id=s.category_id WHERE s.slug=?'.($public?" AND s.status='published'":''),[$slug]);
        return $row===null?null:$this->seminarProjection($row);
    }

    public function referents(bool $public = true, ?int $seminarId = null): array
    {
        $sql='SELECT r.* FROM referent_profiles r WHERE 1=1'; $args=[];
        if ($public) { $sql.=" AND r.status='published'"; }
        if ($seminarId!==null) { $sql.=' AND EXISTS(SELECT 1 FROM seminar_referents sr WHERE sr.referent_id=r.id AND sr.seminar_id=?)'; $args[]=$seminarId; }
        return array_map(fn(array $row): array=>$this->referentProjection($row,$public),$this->db->run($sql.' ORDER BY r.name LIMIT 200',$args)->fetchAll());
    }

    public function referent(string $slug, bool $public = true): ?array
    {
        $row=$this->db->one('SELECT * FROM referent_profiles WHERE slug=?'.($public?" AND status='published'":''),[$slug]);
        return $row===null?null:$this->referentProjection($row,$public);
    }

    public function featuredCount(?int $exceptId = null): int
    {
        return (int)$this->db->run('SELECT count(*) FROM seminars WHERE featured=1'.($exceptId===null?'':' AND id<>?'),$exceptId===null?[]:[$exceptId])->fetchColumn();
    }

    public function saveSeminar(array $data, ?array $existing, array $referentIds, int $now): int
    {
        $params=[$data['slug'],$data['title'],$data['category_id'],$data['summary'],$data['description'],json_encode($data['benefits'],JSON_THROW_ON_ERROR),$data['audience'],$data['duration'],$data['format'],$data['status'],$data['featured'],$data['image_path'],json_encode($data['duration_options'],JSON_THROW_ON_ERROR),$now];
        if ($existing===null) {
            // PostgreSQL liefert die erzeugte Identität nur über RETURNING zurück.
            $id=(int)$this->db->run('INSERT INTO seminars(slug,title,category_id,summary,description,benefits_json,audience,duration,format,status,featured,image_path,duration_options,updated_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id',[...$params,$now])->fetchColumn();
        } else {
            $changed=$this->db->run('UPDATE seminars SET slug=?,title=?,category_id=?,summary=?,description=?,benefits_json=?,audience=?,duration=?,format=?,status=?,featured=?,image_path=?,duration_options=?,updated_at=?,row_version=row_version+1 WHERE id=? AND row_version=?',[...$params,$existing['id'],$data['row_version']])->rowCount();
            if ($changed!==1) { throw new \DomainException('Das Seminar wurde zwischenzeitlich geändert. Bitte die Seite neu öffnen.'); }
            $id=(int)$existing['id'];
        }
        $this->db->run('DELETE FROM seminar_referents WHERE seminar_id=?',[$id]);
        foreach ($referentIds as $referentId) { $this->db->run('INSERT INTO seminar_referents(seminar_id,referent_id) VALUES(?,?)',[$id,$referentId]); }
        return $id;
    }

    public function saveReferent(array $data, ?array $existing, int $now): int
    {
        $params=[$data['slug'],$data['name'],$data['headline'],$data['corps'],$data['biography'],json_encode($data['expertise'],JSON_THROW_ON_ERROR),$data['status'],$data['image_path'],$data['linkedin_url'],$data['cv_path'],json_encode($data['corps_memberships'],JSON_THROW_ON_ERROR),json_encode($data['career'],JSON_THROW_ON_ERROR),$data['user_id']??null,$now];
        if ($existing===null) {
            return (int)$this->db->run('INSERT INTO referent_profiles(slug,name,headline,corps,biography,expertise_json,status,image_path,linkedin_url,cv_path,corps_memberships,career_json,user_id,updated_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id',[...$params,$now])->fetchColumn();
        }
        $changed=$this->db->run('UPDATE referent_profiles SET slug=?,name=?,headline=?,corps=?,biography=?,expertise_json=?,status=?,image_path=?,linkedin_url=?,cv_path=?,corps_memberships=?,career_json=?,user_id=?,updated_at=?,row_version=row_version+1 WHERE id=? AND row_version=?',[...$params,$existing['id'],$data['row_version']])->rowCount();
        if ($changed!==1) { throw new \DomainException('Das Profil wurde zwischenzeitlich geändert. Bitte die Seite neu öffnen.'); }
        return (int)$existing['id'];
    }

    private function seminarProjection(array $row): array
    {
        // PostgreSQL liefert numerische Spalten als Zeichenketten; für strikte Vergleiche normalisieren.
        $row['id']=(int)$row['id']; $row['category_id']=(int)$row['category_id'];
        $row['featured']=(int)$row['featured']; $row['row_version']=(int)$row['row_version'];
        $row['benefits']=json_decode($row['benefits_json'],true,512,JSON_THROW_ON_ERROR);
        $row['duration_options']=json_decode($row['duration_options'],true,512,JSON_THROW_ON_ERROR);
        // Die Textspalte duration bleibt für bestehende Datensätze erhalten und wird aus der Auswahl abgeleitet.
        if ($row['duration_options']!==[]) { $row['duration']=implode(' / ',$row['duration_options']); }
        return $row;
    }

    private function referentProjection(array $row, bool $public): array
    {
        // Only editorial profile fields are public, never the linked account or its contact data.
        if ($public) { unset($row['user_id']); } else { $row['user_id']=$row['user_id']===null?null:(int)$row['user_id']; }
        $row['id']=(int)$row['id']; $row['row_version']=(int)$row['row_version'];
        $row['expertise']=json_decode($row['expertise_json'],true,512,JSON_THROW_ON_ERROR);
        $row['career']=json_decode($row['career_json'],true,512,JSON_THROW_ON_ERROR);
        $memberships=json_decode($row['corps_memberships'],true,512,JSON_THROW_ON_ERROR);
        // Altdaten ohne strukturierte Mitgliedschaften werden für Formular und Anzeige aufgeteilt.
        $row['corps_memberships']=$memberships===[]&&$row['corps']!==''?array_values(array_filter(array_map('trim',explode('·',$row['corps'])))):$memberships;
        if ($row['corps_memberships']!==[]) { $row['corps']=implode(' · ',$row['corps_memberships']); }
        $words=preg_split('/\s+/u',$row['name']);
        $row['initials']=mb_substr($words[0],0,1).mb_substr($words[count($words)-1],0,1);
        return $row;
    }
}
