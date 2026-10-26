<?php
declare(strict_types=1);

namespace Corpsakademie\Deployment;

final class Runtime
{
    public const EXTENSIONS=['curl','intl','mbstring','pdo_sqlite','sodium','xmlwriter','phar'];

    public static function inspect(): array
    {
        $missing=array_values(array_filter(self::EXTENSIONS,static fn(string $extension): bool=>!extension_loaded($extension)));
        $sqlite=null;
        if (extension_loaded('pdo_sqlite')) { $sqlite=(new \PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn(); }
        return ['php'=>PHP_VERSION,'sqlite'=>$sqlite,'missing'=>$missing,'argon2id'=>in_array('argon2id',password_algos(),true)];
    }

    public static function validate(array $data): void
    {
        $version=$data['php']??'';
        if (!is_string($version) || !\Corpsakademie\Infrastructure\Config::runtimeSupported($version)) { throw new \RuntimeException('PHP-Version nicht freigegeben: '.(is_string($version)?$version:'unbekannt')); }
        $sqlite=$data['sqlite']??null;
        if (!is_string($sqlite) || version_compare($sqlite,'3.51.3','<') || $sqlite==='3.52.0') { throw new \RuntimeException('SQLite nicht freigegeben. Benötigt wird mindestens 3.51.3, ausgenommen 3.52.0.'); }
        if (($data['missing']??null)!==[]) { throw new \RuntimeException('Benötigte PHP-Erweiterungen fehlen. CLI und Web-PHP getrennt prüfen.'); }
        if (($data['argon2id']??false)!==true) { throw new \RuntimeException('PHP benötigt Argon2id-Unterstützung.'); }
    }

    public static function probe(string $nonce, int $expires): string
    {
        // Deliberately compatible with older PHP: the probe must report a
        // mismatch instead of loading the application or revealing phpinfo().
        return '<?php '.str_replace(['__NONCE__','__EXPIRES__'],[var_export($nonce,true),(string)$expires], <<<'PHP'
if (time()>__EXPIRES__) { http_response_code(404); exit; }
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
$missing=array();
foreach(array('curl','intl','mbstring','pdo_sqlite','sodium','xmlwriter','phar') as $extension) { if(!extension_loaded($extension)){$missing[]=$extension;} }
$sqlite=null;
if(extension_loaded('pdo_sqlite')) { $db=new PDO('sqlite::memory:');$sqlite=$db->query('SELECT sqlite_version()')->fetchColumn(); }
$https=isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && strtolower($_SERVER['HTTPS'])!=='off';
echo json_encode(array('nonce'=>__NONCE__,'php'=>PHP_VERSION,'sqlite'=>$sqlite,'missing'=>$missing,'argon2id'=>function_exists('password_algos') && in_array('argon2id',password_algos(),true),'https'=>$https));
PHP);
    }
}
