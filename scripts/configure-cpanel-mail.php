<?php
declare(strict_types=1);

// Run interactively on the server. Passwords never belong in shell arguments or Git.
if (PHP_SAPI!=='cli' || !function_exists('stream_isatty') || !stream_isatty(STDIN)) { fwrite(STDERR,"Nur interaktiv im Server-Terminal ausführen.\n"); exit(1); }
umask(0077);
ini_set('display_errors','0');
ini_set('log_errors','0');
$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';
$base='/home/pushgobt/corps-akademie.r4av.com';
$envFile=$base.'/shared/.env';
if (!is_file($envFile) || is_link($envFile)) { fwrite(STDERR,"Private Konfiguration fehlt oder ist ein Symlink.\n"); exit(1); }

function password(string $label): string
{
    $terminal=trim((string)shell_exec('stty -g'));
    if ($terminal==='' || !preg_match('/^[a-zA-Z0-9:;=-]+$/D',$terminal)) { throw new RuntimeException('Terminalschutz nicht verfügbar.'); }
    fwrite(STDOUT,$label.': ');
    system('stty -echo',$code);
    if ($code!==0) { throw new RuntimeException('Passworteingabe konnte nicht geschützt werden.'); }
    try { $value=fgets(STDIN); } finally { system('stty '.escapeshellarg($terminal)); fwrite(STDOUT,PHP_EOL); }
    if ($value===false || rtrim($value,"\r\n")==='') { throw new RuntimeException('Eingabe fehlt.'); }
    return rtrim($value,"\r\n");
}

try {
    echo "SMTP für system@corps-akademie.r4av.com und newsletter@corps-akademie.r4av.com einrichten.\n";
    echo "Passwörter bestehender Postfächer eingeben; die Eingabe bleibt unsichtbar. Es werden keine E-Mails versendet.\n";
    $system=password('Passwort system@corps-akademie.r4av.com');
    $newsletter=password('Passwort newsletter@corps-akademie.r4av.com');
    $dsn=static fn(string $user,string $secret): string=>'smtps://'.rawurlencode($user).':'.rawurlencode($secret).'@premium323.web-hosting.com:465';
    $updates=[
        'APP_MAIL_ENABLED'=>'true',
        'MAILER_DSN'=>$dsn('system@corps-akademie.r4av.com',$system),
        'MAIL_FROM'=>'system@corps-akademie.r4av.com',
        'NEWSLETTER_MAILER_DSN'=>$dsn('newsletter@corps-akademie.r4av.com',$newsletter),
        'NEWSLETTER_MAIL_FROM'=>'newsletter@corps-akademie.r4av.com',
        'MAIL_REPLY_TO'=>'info@corps-akademie.r4av.com',
        'MAIL_CONTACT_TO'=>'info@corps-akademie.r4av.com',
    ];
    unset($system,$newsletter);
    foreach (['MAILER_DSN','NEWSLETTER_MAILER_DSN'] as $key) {
        $transport=Symfony\Component\Mailer\Transport::fromDsn($updates[$key]);
        if (!$transport instanceof Symfony\Component\Mailer\Transport\Smtp\SmtpTransport) { throw new RuntimeException('SMTP erforderlich.'); }
        $transport->getStream()->setTimeout(10);
        $transport->start(); $transport->stop();
        echo $key.': TLS-Verbindung und Anmeldung erfolgreich.'.PHP_EOL;
    }
    $lock=fopen($base.'/shared/runtime.lock','r+');
    if ($lock===false || !flock($lock,LOCK_EX|LOCK_NB) || is_file($base.'/shared/maintenance.flag')) { throw new RuntimeException('Wartung oder andere Verarbeitung aktiv. Später erneut versuchen.'); }
    $original=file_get_contents($envFile);
    $values=(new Symfony\Component\Dotenv\Dotenv())->parse($original);
    new Corpsakademie\Infrastructure\Config($root,array_replace($values,$updates));
    $content=$original;
    foreach ($updates as $key=>$value) {
        $line=$key.'="'.str_replace(['\\','"','$'],['\\\\','\\"','\\$'],$value).'"';
        if (preg_match('/^'.preg_quote($key,'/').'=/m',$content)) { $content=preg_replace_callback('/^'.preg_quote($key,'/').'=.*$/m',static fn()=>$line,$content); }
        else { $content.=PHP_EOL.$line.PHP_EOL; }
    }
    $directory=$base.'/shared/deployment-backups';
    if (!is_dir($directory) && !mkdir($directory,0700,true)) { throw new RuntimeException('Sicherungsordner fehlt.'); }
    $backup=$directory.'/env-before-mail-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5));
    if (file_put_contents($backup,$original,LOCK_EX)!==strlen($original)) { throw new RuntimeException('Sicherung fehlgeschlagen.'); }
    chmod($backup,0600);
    $temporary=$base.'/shared/.env-mail-'.bin2hex(random_bytes(8));
    if (file_put_contents($temporary,$content,LOCK_EX)!==strlen($content)) { throw new RuntimeException('Konfiguration konnte nicht geschrieben werden.'); }
    chmod($temporary,0600);
    if (!rename($temporary,$envFile)) { throw new RuntimeException('Aktivierung fehlgeschlagen.'); }
    echo "Mailkonfiguration aktiviert. Jetzt Cronjobs und Zustellung an eine eigene Testadresse prüfen.\n";
} catch (Throwable $error) {
    // SMTP exception messages can contain credentials or server conversation details.
    fwrite(STDERR,'Einrichtung abgebrochen ('.get_debug_type($error)."). Keine SMTP-Details ausgegeben. Bestehende Konfiguration bleibt vor Aktivierung erhalten.\n");
    exit(1);
}
