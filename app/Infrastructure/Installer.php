<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

use Corpsakademie\Container;

final class Installer
{
    public static function install(string $root): array
    {
        umask(0077);
        if (!is_file($root.'/.env')) {
            $template = file_get_contents($root.'/.env.example');
            $contents = preg_replace('/^APP_KEY=.*$/m','APP_KEY="'.base64_encode(random_bytes(32)).'"',$template);
            $file = fopen($root.'/.env','x');
            if ($file===false) { throw new \RuntimeException('Konfiguration konnte nicht erstellt werden.'); }
            try {
                if (fwrite($file,$contents)!==strlen($contents)) { throw new \RuntimeException('Konfiguration konnte nicht vollständig geschrieben werden.'); }
            } finally { fclose($file); }
            chmod($root.'/.env',0600);
        }
        $container = new Container(Config::load($root));
        $applied = (new Migrator($container->db,$root.'/database/migrations'))->migrate();
        return $applied;
    }
}
