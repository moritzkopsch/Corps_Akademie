<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

final class CatalogSeeder
{
    public static function seed(Database $db, CatalogRepository $catalog, string $root, int $now, bool $presentation = false): array
    {
        if (!in_array($db->config->environment,['local','test'],true) && !($presentation && !$db->config->mailEnabled)) { throw new \RuntimeException('Präsentationsinhalte sind nur lokal/in Tests oder ausdrücklich mit --presentation bei deaktiviertem Mailversand erlaubt.'); }
        $content=require $root.'/resources/content/catalog.php';
        return $db->immediate(function() use ($db,$catalog,$content,$now): array {
            $counts=['seminars'=>0,'referents'=>0];
            foreach ($content['referents'] as $row) {
                if ($catalog->referent($row['slug'],false)!==null) { continue; }
                $catalog->saveReferent($row+['status'=>'published'],null,$now);
                $db->run('UPDATE referent_profiles SET source_url=? WHERE slug=?',[$row['source_url'],$row['slug']]);
                $counts['referents']++;
            }
            foreach ($content['seminars'] as [$slug,$title,$category,$summary,$description,$benefits,$audience,$duration,$referents,$featured]) {
                if ($catalog->seminar($slug,false)!==null) { continue; }
                $categoryId=(int)$db->one('SELECT id FROM seminar_categories WHERE slug=?',[$category])['id'];
                $ids=[];
                foreach ($referents as $referent) { $ids[]=(int)$catalog->referent($referent,false)['id']; }
                $catalog->saveSeminar(['slug'=>$slug,'title'=>$title,'category_id'=>$categoryId,'summary'=>$summary,'description'=>$description,'benefits'=>$benefits,'audience'=>$audience,'duration'=>$duration,'format'=>'Präsenz / nach Vereinbarung','status'=>'published','featured'=>$featured],null,$ids,$now);
                $counts['seminars']++;
            }
            return $counts;
        });
    }
}
