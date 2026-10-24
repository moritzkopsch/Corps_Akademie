<?php
declare(strict_types=1);

// Opt-in local integration check. Requires the webserver and project Mailpit.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/vendor/autoload.php';

use Corpsakademie\Container;
use Corpsakademie\Infrastructure\{Config,Migrator};

function fetchLocal(string $url,array $headers=[]): array
{
    $request=curl_init($url);
    curl_setopt_array($request,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_PROXY=>'',CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>5,CURLOPT_HTTPHEADER=>$headers]);
    $body=curl_exec($request);
    if ($body===false) { throw new RuntimeException('Ein lokaler Dienst ist nicht erreichbar.'); }
    return ['status'=>curl_getinfo($request,CURLINFO_RESPONSE_CODE),'body'=>$body];
}

$directory=null; $c=null;
try {
    if (fetchLocal('http://127.0.0.1:8080/anmelden')['status']!==200) { throw new RuntimeException('Die Anmeldung ist nicht erreichbar.'); }
    foreach (['/.env','/storage/database/application.sqlite','/vendor/autoload.php','/database/migrations/001_identity.sql','/app/Container.php'] as $path) {
        if (fetchLocal('http://127.0.0.1:8080'.$path)['status']!==404) { throw new RuntimeException('Privater Pfad ist nicht korrekt abgeschirmt: '.$path); }
    }
    if (fetchLocal('http://127.0.0.1:8080/admin')['status']!==303) { throw new RuntimeException('Administrationszugriff wird nicht zur Anmeldung geleitet.'); }
    if (fetchLocal('http://127.0.0.1:8080/',['Host: attacker.example'])['status']!==400) { throw new RuntimeException('Fremder Host-Header wurde nicht abgewiesen.'); }
    $info=json_decode(fetchLocal('http://127.0.0.1:8025/api/v1/webui')['body'],true,512,JSON_THROW_ON_ERROR);
    if (($info['Label']??'')!=='Corpsakademie Lokal') { throw new RuntimeException('Das Projekt-Testpostfach fehlt.'); }
    echo "[OK] Anmeldung und Zugriffsgrenzen am laufenden Webserver.\n";

    umask(0077);
    $directory=sys_get_temp_dir().'/corpsakademie-live-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
    $root=dirname(__DIR__);
    $c=new Container(new Config($root,[
        'APP_ENV'=>'test','APP_URL'=>'http://127.0.0.1:8080','APP_KEY'=>base64_encode(random_bytes(32)),
        'APP_DB_PATH'=>$directory.'/database.sqlite','APP_STORAGE_PATH'=>$directory.'/storage',
        'MAILER_DSN'=>'smtp://127.0.0.1:1025','MAIL_FROM'=>'system@example.test',
    ]));
    (new Migrator($c->db,$root.'/database/migrations'))->migrate();
    $recipient='smtp-check-'.bin2hex(random_bytes(8)).'@example.test';
    $user=$c->accounts->create($recipient,'Temporäre SMTP-Prüfung',bin2hex(random_bytes(24)),'instructor',true);
    $c->links->requestReset($recipient,'127.0.0.1');
    if ($c->mail->work()['submitted']!==1) { throw new RuntimeException('Der echte Anwendungs-Mailer konnte die Nachricht nicht übergeben.'); }
    $matches=[];
    for ($i=0;$i<50;$i++) {
        $list=json_decode(fetchLocal('http://127.0.0.1:8025/api/v1/messages?limit=100')['body'],true,512,JSON_THROW_ON_ERROR);
        $matches=array_values(array_filter($list['messages']??[],static fn(array $mail): bool => ($mail['To'][0]['Address']??'')===$recipient));
        if ($matches!==[]) { break; }
        usleep(100000);
    }
    if (count($matches)!==1) { throw new RuntimeException('Die Nachricht des Anwendungs-Mailers wurde nicht eindeutig gefunden.'); }
    $mail=json_decode(fetchLocal('http://127.0.0.1:8025/api/v1/message/'.rawurlencode($matches[0]['ID']))['body'],true,512,JSON_THROW_ON_ERROR);
    if (!str_contains($mail['Text']??'','/passwort-neu#token=') || !str_contains($mail['HTML']??'','/passwort-neu#token=')) { throw new RuntimeException('Text oder HTML des Anwendungs-Mailers fehlt.'); }
    if ($c->db->one('SELECT secret_payload_ciphertext FROM notification_outbox')['secret_payload_ciphertext']!==null) { throw new RuntimeException('Versandgeheimnis wurde nach Übergabe nicht entfernt.'); }
    echo "[OK] Transaktionale Outbox → Symfony Mailer → SMTP → Mailpit, Text und HTML vorhanden.\n";
    echo "[OK] Versandgeheimnis entfernt; temporäre Anwendungsdaten werden bereinigt.\n";
} catch (Throwable $error) {
    fwrite(STDERR,'[FEHLER] '.$error->getMessage().PHP_EOL);
    $exitCode=1;
} finally {
    $c=null;
    if ($directory!==null && is_dir($directory)) {
        $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) { $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname()); }
        rmdir($directory);
    }
}
exit($exitCode??0);
