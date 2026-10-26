<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/app/Infrastructure/Config.php';
require __DIR__.'/runtime.php';
require __DIR__.'/deployment.php';

use Corpsakademie\Deployment\Deployment;

try {
    $options=getopt('',['base:','init','check','help']);
    if (isset($options['help'])) {
        echo "cPanel: deploy-cpanel.sh [--base /privater/pfad] [--init | --check]\n";
        echo "--init: neue private Vorlagen erstellen; vorhandene Dateien bleiben erhalten.\n";
        echo "--check: CLI und private Konfiguration ohne Veröffentlichung prüfen.\n";
        exit(0);
    }
    $base=$options['base']??rtrim((string)getenv('HOME'),'/').'/apps/corpsakademie';
    if (!is_string($base) || (isset($options['init']) && isset($options['check']))) { throw new RuntimeException('Ungültige Optionen.'); }
    $deployment=new Deployment(dirname(__DIR__,2),$base);
    if (isset($options['init'])) { $deployment->initialize(); }
    elseif (isset($options['check'])) { $deployment->check(); }
    else { $deployment->deploy(); }
} catch (Throwable $error) {
    // Never print traces or dotenv/parser messages containing SMTP credentials.
    $message=$error instanceof RuntimeException?$error->getMessage():'Prüfung fehlgeschlagen. Details lokal prüfen; Zugangsdaten nicht veröffentlichen.';
    fwrite(STDERR,'[FEHLER] '.$message.PHP_EOL);
    exit(1);
}
