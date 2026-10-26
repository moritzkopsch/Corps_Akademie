<?php
declare(strict_types=1);

namespace Corpsakademie\Deployment;

final class Runtime
{
    public const EXTENSIONS=['curl','intl','mbstring','pdo_pgsql','sodium','xmlwriter','phar'];

    public static function inspect(): array
    {
        $missing=array_values(array_filter(self::EXTENSIONS,static fn(string $extension): bool=>!extension_loaded($extension)));
        return ['php'=>PHP_VERSION,'pgsql'=>self::driver(),'missing'=>$missing,'argon2id'=>in_array('argon2id',password_algos(),true)];
    }

    public static function validate(array $data): void
    {
        $version=$data['php']??'';
        if (!is_string($version) || !\Corpsakademie\Infrastructure\Config::runtimeSupported($version)) { throw new \RuntimeException('PHP-Version nicht freigegeben: '.(is_string($version)?$version:'unbekannt')); }
        if (!is_string($data['pgsql']??null) || $data['pgsql']==='') { throw new \RuntimeException('PDO_Treiber pgsql fehlt. Die Erweiterung pdo_pgsql muss in CLI und Web aktiv sein.'); }
        if (($data['missing']??null)!==[]) { throw new \RuntimeException('Benötigte PHP-Erweiterungen fehlen. CLI und Web-PHP getrennt prüfen.'); }
        if (($data['argon2id']??false)!==true) { throw new \RuntimeException('PHP benötigt Argon2id-Unterstützung.'); }
    }

    // The PostgreSQL server version is verified when the application connects; the
    // probe must not carry database credentials into the public webroot.
    private static function driver(): ?string
    {
        if (!extension_loaded('pdo_pgsql') || !in_array('pgsql',\PDO::getAvailableDrivers(),true)) { return null; }
        return (string) (phpversion('pdo_pgsql') ?: 'aktiv');
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
foreach(array('curl','intl','mbstring','pdo_pgsql','sodium','xmlwriter','phar') as $extension) { if(!extension_loaded($extension)){$missing[]=$extension;} }
$pgsql=null;
if(extension_loaded('pdo_pgsql') && in_array('pgsql',PDO::getAvailableDrivers(),true)) { $pgsql=(string)phpversion('pdo_pgsql'); }
$https=isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] && strtolower($_SERVER['HTTPS'])!=='off';
echo json_encode(array('nonce'=>__NONCE__,'php'=>PHP_VERSION,'pgsql'=>$pgsql,'missing'=>$missing,'argon2id'=>function_exists('password_algos') && in_array('argon2id',password_algos(),true),'https'=>$https));
PHP);
    }
}
