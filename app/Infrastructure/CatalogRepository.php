<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

final class CatalogRepository
{
    public function __construct(private readonly Database $db) {}

    public function categories(): array { return $this->db->run('SELECT * FROM seminar_categories ORDER BY position')->fetchAll(); }

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
        return array_map($this->referentProjection(...),$this->db->run($sql.' ORDER BY r.name LIMIT 200',$args)->fetchAll());
    }

    public function referent(string $slug, bool $public = true): ?array
    {
        $row=$this->db->one('SELECT * FROM referent_profiles WHERE slug=?'.($public?" AND status='published'":''),[$slug]);
        return $row===null?null:$this->referentProjection($row);
    }

    public function saveSeminar(array $data, ?array $existing, array $referentIds, int $now): int
    {
        $params=[$data['slug'],$data['title'],$data['category_id'],$data['summary'],$data['description'],json_encode($data['benefits'],JSON_THROW_ON_ERROR),$data['audience'],$data['duration'],$data['format'],$data['status'],$data['featured'],$now];
        if ($existing===null) {
            $this->db->run('INSERT INTO seminars(slug,title,category_id,summary,description,benefits_json,audience,duration,format,status,featured,updated_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[...$params,$now]);
            $id=(int)$this->db->pdo->lastInsertId();
        } else {
            $changed=$this->db->run('UPDATE seminars SET slug=?,title=?,category_id=?,summary=?,description=?,benefits_json=?,audience=?,duration=?,format=?,status=?,featured=?,updated_at=?,row_version=row_version+1 WHERE id=? AND row_version=?',[...$params,$existing['id'],$data['row_version']])->rowCount();
            if ($changed!==1) { throw new \DomainException('Das Seminar wurde zwischenzeitlich geändert. Bitte die Seite neu öffnen.'); }
            $id=(int)$existing['id'];
        }
        $this->db->run('DELETE FROM seminar_referents WHERE seminar_id=?',[$id]);
        foreach ($referentIds as $referentId) { $this->db->run('INSERT INTO seminar_referents(seminar_id,referent_id) VALUES(?,?)',[$id,$referentId]); }
        return $id;
    }

    public function saveReferent(array $data, ?array $existing, int $now): int
    {
        $params=[$data['slug'],$data['name'],$data['headline'],$data['corps'],$data['biography'],json_encode($data['expertise'],JSON_THROW_ON_ERROR),$data['status'],$now];
        if ($existing===null) {
            $this->db->run('INSERT INTO referent_profiles(slug,name,headline,corps,biography,expertise_json,status,updated_at,created_at) VALUES(?,?,?,?,?,?,?,?,?)',[...$params,$now]);
            return (int)$this->db->pdo->lastInsertId();
        }
        $changed=$this->db->run('UPDATE referent_profiles SET slug=?,name=?,headline=?,corps=?,biography=?,expertise_json=?,status=?,updated_at=?,row_version=row_version+1 WHERE id=? AND row_version=?',[...$params,$existing['id'],$data['row_version']])->rowCount();
        if ($changed!==1) { throw new \DomainException('Das Profil wurde zwischenzeitlich geändert. Bitte die Seite neu öffnen.'); }
        return (int)$existing['id'];
    }

    private function seminarProjection(array $row): array
    {
        $row['benefits']=json_decode($row['benefits_json'],true,512,JSON_THROW_ON_ERROR);
        return $row;
    }

    private function referentProjection(array $row): array
    {
        // Only editorial profile fields are public, never the linked account or its contact data.
        unset($row['user_id']);
        $row['expertise']=json_decode($row['expertise_json'],true,512,JSON_THROW_ON_ERROR);
        $words=preg_split('/\s+/u',$row['name']);
        $row['initials']=mb_substr($words[0],0,1).mb_substr($words[count($words)-1],0,1);
        return $row;
    }
}
