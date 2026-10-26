<?php
declare(strict_types=1);

namespace Corpsakademie\Deployment;

use Corpsakademie\Infrastructure\Config;

final class Deployment
{
    public const MARKER='CORPSAKADEMIE CPANEL ENTRY';
    private string $base;

    public function __construct(private readonly string $source, string $base, private readonly ?\Closure $httpsGet = null)
    {
        if (!str_starts_with($base,'/') || str_contains($base,"\0") || str_contains($base,'..') || str_contains($base,'://')) { throw new \RuntimeException('Die private Basis benötigt einen absoluten, eindeutigen Dateipfad.'); }
        $this->base=rtrim($base,'/');
    }

    public function initialize(): void
    {
        Runtime::validate(Runtime::inspect());
        $shared=$this->base.'/shared';
        if (!is_file($shared.'/.env') && is_file($this->source.'/.env')) {
            throw new \RuntimeException('Im Checkout liegt bereits eine Konfiguration. Zuerst vorhandenen Schlüssel sichern und zusammen übernehmen; es wurde kein neuer Schlüssel angelegt.');
        }
        foreach ([$this->base,$shared,$shared.'/storage',$shared.'/storage/logs',$shared.'/storage/backups',$this->base.'/releases'] as $directory) { $this->directory($directory,0700); }
        $this->base=realpath($this->base);
        $shared=$this->base.'/shared';
        if (!is_file($shared.'/deploy.json')) {
            $settings=['webroot'=>rtrim((string)getenv('HOME'),'/').'/public_html','composer'=>rtrim((string)getenv('HOME'),'/').'/bin/composer','replace_existing_site'=>false];
            $this->newFile($shared.'/deploy.json',json_encode($settings,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
        }
        if (!is_file($shared.'/.env')) {
            $env="APP_ENV=production\nAPP_URL=https://CHANGE-ME.invalid\nAPP_KEY=\"".base64_encode(random_bytes(32))."\"\nAPP_KEY_ID=v1\n";
            $env.="APP_DB_HOST=localhost\nAPP_DB_PORT=5432\nAPP_DB_NAME=CHANGE-ME\nAPP_DB_USER=CHANGE-ME\nAPP_DB_PASSWORD=\"CHANGE-ME\"\n";
            $env.='APP_STORAGE_PATH="'.$shared.'/storage"'."\n";
            $env.="SESSION_IDLE_SECONDS=1800\nSESSION_ABSOLUTE_SECONDS=43200\nMAILER_DSN=\"smtps://CHANGE-ME:CHANGE-ME@CHANGE-ME.invalid:465\"\nMAIL_FROM=CHANGE-ME@CHANGE-ME.invalid\n";
            $this->newFile($shared.'/.env',$env);
        }
        $this->newFile($shared.'/runtime.lock','');
        echo "[OK] Private Einrichtung vorhanden: ".$shared."\n";
        echo "Jetzt APP_URL, PostgreSQL-Zugang, SMTP und MAIL_FROM in shared/.env sowie Webroot/Composer in shared/deploy.json anpassen.\n";
        echo "Vorhandene Dateien und Schlüssel wurden nicht überschrieben. Danach --check ausführen.\n";
    }

    public function check(): array
    {
        $runtime=Runtime::inspect(); Runtime::validate($runtime);
        echo '[OK] CLI PHP '.$runtime['php'].' / pdo_pgsql '.$runtime['pgsql']."\n";
        if (!is_dir($this->base.'/shared')) { throw new \RuntimeException('Private Einrichtung fehlt. Zuerst deploy-cpanel.sh --init mit demselben --base ausführen.'); }
        $this->base=realpath($this->base);
        $shared=$this->base.'/shared';
        $configFile=$shared.'/deploy.json';
        if (!is_file($configFile)) { throw new \RuntimeException('shared/deploy.json fehlt. Zuerst --init ausführen.'); }
        try { $settings=json_decode(file_get_contents($configFile),true,32,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \RuntimeException('shared/deploy.json enthält kein gültiges JSON.'); }
        if (!is_array($settings)) { throw new \RuntimeException('shared/deploy.json benötigt ein JSON-Objekt.'); }
        foreach (['webroot','composer'] as $key) {
            if (!is_string($settings[$key]??null) || !str_starts_with($settings[$key],'/') || str_contains($settings[$key],"\0")) { throw new \RuntimeException('Webroot und Composer benötigen absolute Pfade in shared/deploy.json.'); }
        }
        $webroot=realpath($settings['webroot']);
        if ($webroot===false || !is_dir($webroot) || !is_writable($webroot)) { throw new \RuntimeException('Der konfigurierte Webroot existiert nicht oder ist nicht beschreibbar.'); }
        $source=realpath($this->source);
        if ($this->inside($this->base,$webroot) || $this->inside($source,$webroot) || $this->inside($webroot,$this->base) || $this->inside($webroot,$source)) { throw new \RuntimeException('Webroot, privates Releaseverzeichnis und Git-Checkout müssen getrennt liegen.'); }
        $settings['webroot']=$webroot;
        foreach (['.env','.env.local','.git','vendor','storage','app','bin','config','database','resources','tests','deployment','scripts','composer.json','composer.lock'] as $privateName) {
            if (file_exists($webroot.'/'.$privateName) || is_link($webroot.'/'.$privateName)) { throw new \RuntimeException('Im Webroot liegen private Projektdateien oder alte Installationsdaten. Vor dem Deployment außerhalb des Webroots sichern und den Zielordner bereinigen.'); }
        }
        $webFiles=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($webroot,\FilesystemIterator::SKIP_DOTS));
        foreach ($webFiles as $webFile) {
            if (strtolower($webFile->getExtension())==='php' && $webFile->getPathname()!==$webroot.'/index.php') { throw new \RuntimeException('Im Ziel liegen weitere PHP-Endpunkte einer alten Website. Zuerst vollständig sichern und aus dem Ziel-Webroot herausnehmen.'); }
        }
        if (!is_file($settings['composer']) || !is_readable($settings['composer'])) { throw new \RuntimeException('Lokaler Composer fehlt am konfigurierten Pfad. shared/deploy.json prüfen.'); }
        if (!is_file($shared.'/.env')) { throw new \RuntimeException('Die private shared/.env fehlt.'); }
        if ((fileperms($shared.'/.env')&0077)!==0) { throw new \RuntimeException('shared/.env benötigt private Dateirechte, beispielsweise chmod 600.'); }
        $contents=file_get_contents($shared.'/.env');
        if (stripos($contents,'CHANGE-ME')!==false) { throw new \RuntimeException('shared/.env enthält noch Platzhalter. APP_URL, SMTP und Absender zuerst eintragen.'); }
        foreach ([$shared.'/storage',$this->base.'/releases'] as $directory) {
            if (!is_dir($directory) || !is_writable($directory)) { throw new \RuntimeException('Private Speicherverzeichnisse fehlen oder sind nicht beschreibbar. --init beziehungsweise Dateirechte prüfen.'); }
        }
        if (!is_file($shared.'/runtime.lock') || !is_writable($shared.'/runtime.lock')) { throw new \RuntimeException('shared/runtime.lock fehlt oder ist nicht beschreibbar. --init ausführen.'); }
        $vendor=$this->source.'/vendor/autoload.php';
        if (!is_file($vendor)) {
            echo "[HINWEIS] Composer-Abhängigkeiten fehlen im Checkout; vollständige Konfigurationsprüfung erfolgt im neuen Release vor der Aktivierung.\n";
        } else { $this->applicationConfig($this->source); }
        if (is_link($webroot.'/index.php') || is_link($webroot.'/.htaccess') || is_link($webroot.'/assets')) { throw new \RuntimeException('Index, .htaccess und Assets im Webroot dürfen für diese Bereitstellung keine Symlinks sein.'); }
        $existing=is_file($webroot.'/index.php')?file_get_contents($webroot.'/index.php'):'';
        if (($existing!=='' && !str_contains($existing,self::MARKER)) || (is_file($webroot.'/index.html') && $existing==='')) {
            if (($settings['replace_existing_site']??false)!==true) { throw new \RuntimeException('Im Ziel liegt bereits eine andere Website. Zuerst sichern beziehungsweise einen leeren Vorschau-Webroot wählen. Eine bewusste Übernahme erfordert replace_existing_site=true in shared/deploy.json.'); }
        }
        $this->htaccess(is_file($webroot.'/.htaccess')?file_get_contents($webroot.'/.htaccess'):'');
        if (file_exists($this->base.'/current') && !is_link($this->base.'/current')) { throw new \RuntimeException('current muss ein Deployment-Symlink sein. Bestehenden Ordner zuerst prüfen.'); }
        echo "[OK] Private Pfade, Webroot und lokale Composer-Datei geprüft.\n";
        echo "[HINWEIS] Web-PHP, HTTPS und Composer-Plattform werden beim Deployment gesondert geprüft. --check veröffentlicht nichts.\n";
        return $settings;
    }

    public function deploy(): void
    {
        $settings=$this->check();
        $this->command(['git','diff','--quiet'], $this->source);
        $this->command(['git','diff','--cached','--quiet'], $this->source);
        $revision=trim($this->capture(['git','rev-parse','HEAD'],$this->source));
        if (!preg_match('/^[a-f0-9]{40}$/D',$revision)) { throw new \RuntimeException('Git-Commit konnte nicht ermittelt werden.'); }
        $release=$this->base.'/releases/'.substr($revision,0,12).'-'.bin2hex(random_bytes(6));
        $this->directory($release,0700);
        $archive=$release.'/source.tar';
        $this->command(['git','archive','--format=tar','--output='.$archive,$revision],$this->source);
        $this->command(['tar','-xf',$archive,'-C',$release],$this->source);
        unlink($archive);
        if (!symlink($this->base.'/shared/.env',$release.'/.env')) { throw new \RuntimeException('Private Konfiguration konnte nicht mit dem Release verbunden werden.'); }
        $this->command([PHP_BINARY,$settings['composer'],'install','--no-dev','--prefer-dist','--optimize-autoloader','--no-interaction'],$release);
        $this->command([PHP_BINARY,$settings['composer'],'check-platform-reqs','--no-dev'],$release);
        $config=$this->applicationConfig($release);
        $this->webProbe($settings['webroot'],$config->url);
        $assets=$this->assetFiles($release.'/public/assets');
        $rules=$this->htaccess(is_file($settings['webroot'].'/.htaccess')?file_get_contents($settings['webroot'].'/.htaccess'):'',file_get_contents($release.'/public/.htaccess'));
        $entry=$this->entry($release.'/deployment/cpanel/entry.php');
        $lock=fopen($this->base.'/shared/runtime.lock','r+');
        if ($lock===false) { throw new \RuntimeException('Deployment-Sperre konnte nicht geöffnet werden.'); }
        $deadline=microtime(true)+45;
        while (!flock($lock,LOCK_EX|LOCK_NB)) {
            if (microtime(true)>$deadline) { fclose($lock); throw new \RuntimeException('Aktive Anfragen oder CLI-Läufe blockieren das Deployment. Später erneut versuchen.'); }
            usleep(100000);
        }
        try {
            $this->backupPublic($settings['webroot']);
            $this->atomicWrite($this->base.'/shared/maintenance.flag',"Deployment ".$revision."\n",0600);
            // First install also gets the maintenance-aware entry before any DB work.
            $this->atomicWrite($settings['webroot'].'/index.php',$entry,0644);
            $this->atomicWrite($settings['webroot'].'/.htaccess',$rules,0644);
            foreach ($assets as $relative=>$source) {
                $target=$settings['webroot'].'/assets/'.$relative;
                $this->publicDirectory(dirname($target),$settings['webroot']);
                if (is_link($target)) { throw new \RuntimeException('Ein Asset-Ziel ist ein Symlink. Veröffentlichung abgebrochen.'); }
                $this->atomicWrite($target,file_get_contents($source),0644);
            }
            $this->command([PHP_BINARY,$release.'/bin/console','db:migrate'],$release);
            $this->command([PHP_BINARY,$release.'/bin/console','doctor'],$release);
            $next=$this->base.'/current-next-'.bin2hex(random_bytes(6));
            if (!symlink($release,$next) || !rename($next,$this->base.'/current')) { throw new \RuntimeException('Die neue Veröffentlichung konnte nicht aktiviert werden.'); }
            $this->atomicWrite($this->base.'/shared/last-deployment.json',json_encode(['commit'=>$revision,'release'=>basename($release),'completed_at'=>gmdate(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",0600);
            if (!unlink($this->base.'/shared/maintenance.flag')) { throw new \RuntimeException('Wartungsmodus konnte nicht beendet werden.'); }
            echo '[OK] Aktiviert: '.$revision."\n";
            echo "Jetzt HTTPS-Aufruf, Anmeldung und Mail-Cron auf dem Server prüfen. Inhalte werden nicht aus lokalen Testdaten übernommen.\n";
        } catch (\Throwable $error) {
            fwrite(STDERR,"[HINWEIS] Wartungsmodus bleibt aktiv, falls er bereits gesetzt wurde. Nach Datenbankänderungen erfolgt kein automatischer Code-Rollback.\n");
            throw $error;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    private function applicationConfig(string $root): Config
    {
        require_once $root.'/vendor/autoload.php';
        try { $values=(new \Symfony\Component\Dotenv\Dotenv())->parse(file_get_contents($this->base.'/shared/.env')); }
        catch (\Throwable) { throw new \RuntimeException('shared/.env konnte nicht gelesen werden. Syntax prüfen; keine Zugangsdaten in Logs kopieren.'); }
        if (($values['APP_ENV']??'')!=='production') { throw new \RuntimeException('Diese Bereitstellung benötigt APP_ENV=production und echten, TLS-gesicherten SMTP-Versand.'); }
        if (($values['APP_STORAGE_PATH']??'')!==$this->base.'/shared/storage') { throw new \RuntimeException('APP_STORAGE_PATH muss auf den angelegten absoluten shared/storage-Pfad zeigen.'); }
        return new Config($root,$values);
    }

    public function webProbe(string $webroot, string $url): void
    {
        if (!str_starts_with($url,'https://')) { throw new \RuntimeException('Die Web-Prüfung benötigt die echte HTTPS-APP_URL.'); }
        $nonce=bin2hex(random_bytes(24)); $name='corps-deploy-check-'.bin2hex(random_bytes(16)).'.php';
        $file=$webroot.'/'.$name;
        $this->newFile($file,Runtime::probe($nonce,time()+120),0644);
        try {
            $probeUrl=rtrim($url,'/').'/'.$name;
            if ($this->httpsGet!==null) {
                [$status,$body]=($this->httpsGet)($probeUrl);
            } else {
                $curl=curl_init($probeUrl);
                curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'']);
                $body=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); curl_close($curl);
            }
            if (!is_string($body) || $status!==200 || strlen($body)>16384) { throw new \RuntimeException('Die HTTPS-Web-Prüfung ist fehlgeschlagen. Domain, Zertifikat, Webroot oder Zugriffsschutz prüfen. Redirects werden nicht übernommen.'); }
            try { $data=json_decode($body,true,32,JSON_THROW_ON_ERROR); } catch (\Throwable) { throw new \RuntimeException('Der Webserver liefert keine gültige PHP-Prüfantwort.'); }
            if (!is_array($data) || !is_string($data['nonce']??null) || !hash_equals($nonce,$data['nonce'])) { throw new \RuntimeException('APP_URL zeigt nicht auf den geprüften Webroot.'); }
            Runtime::validate($data);
            if (($data['https']??false)!==true) { throw new \RuntimeException('PHP erkennt die HTTPS-Verbindung nicht direkt. Hosting-Konfiguration prüfen; Proxy-Header werden nicht pauschal vertraut.'); }
            echo '[OK] Web PHP '.$data['php'].' / pdo_pgsql '.$data['pgsql']." / HTTPS geprüft.\n";
        } finally { if (is_file($file)) { unlink($file); } }
    }

    public function entry(?string $template = null): string
    {
        return str_replace('__CORPS_DEPLOYMENT_BASE__',var_export($this->base,true),file_get_contents($template??__DIR__.'/entry.php'));
    }

    public function htaccess(string $existing, ?string $rules = null): string
    {
        $outside=preg_replace('~\n?# BEGIN CORPSAKADEMIE CPANEL\n.*?# END CORPSAKADEMIE CPANEL\n?~s','',$existing);
        if (preg_match('/^\s*(RewriteRule|RewriteCond|RewriteBase|Redirect|DirectoryIndex)\b/im',$outside)) { throw new \RuntimeException('Im Webroot liegen fremde Weiterleitungsregeln. Vor der Übernahme gezielt prüfen; cPanel-PHP-Handler erhalten.'); }
        $managed="# BEGIN CORPSAKADEMIE CPANEL\n".($rules??file_get_contents($this->source.'/public/.htaccess'))."# END CORPSAKADEMIE CPANEL\n";
        return $managed.($outside===''?'':"\n".ltrim($outside,"\n"));
    }

    public function assetFiles(string $directory): array
    {
        $files=[];
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative=substr($file->getPathname(),strlen($directory)+1);
            if ($file->isLink() || str_contains('/'.$relative,'/.') || !in_array(strtolower($file->getExtension()),['css','js','svg','png','jpg','jpeg','webp','ico','woff','woff2'],true)) { throw new \RuntimeException('Unzulässiger Dateityp oder Symlink in öffentlichen Assets.'); }
            if ($file->isFile()) { $files[$relative]=$file->getPathname(); }
        }
        return $files;
    }

    private function backupPublic(string $webroot): void
    {
        $names=[];
        foreach (['index.php','index.html','.htaccess','assets'] as $name) { if (file_exists($webroot.'/'.$name)) { $names[]=$name; } }
        if ($names===[]) { return; }
        $directory=$this->base.'/shared/deployment-backups'; $this->directory($directory,0700);
        $path=$directory.'/public-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.tar';
        $this->command(['tar','-cf',$path,'-C',$webroot,...$names],$this->source); chmod($path,0600);
    }

    private function command(array $command, string $directory): void
    {
        $process=proc_open($command,[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes,$directory,null,['bypass_shell'=>true]);
        if (!is_resource($process) || proc_close($process)!==0) { throw new \RuntimeException('Deployment-Schritt fehlgeschlagen: '.basename($command[0]).'. Vorangehende Ausgabe prüfen.'); }
    }

    private function capture(array $command, string $directory): string
    {
        $process=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>STDERR],$pipes,$directory,null,['bypass_shell'=>true]);
        if (!is_resource($process)) { throw new \RuntimeException('Git-Aufruf fehlgeschlagen.'); }
        $result=stream_get_contents($pipes[1]); fclose($pipes[1]);
        if (proc_close($process)!==0) { throw new \RuntimeException('Git-Aufruf fehlgeschlagen.'); }
        return $result;
    }

    private function directory(string $directory, int $mode): void
    {
        if (!is_dir($directory) && !mkdir($directory,$mode,true) && !is_dir($directory)) { throw new \RuntimeException('Verzeichnis konnte nicht angelegt werden.'); }
    }

    private function newFile(string $path, string $contents, int $mode = 0600): void
    {
        if (file_exists($path)) { return; }
        $file=fopen($path,'x');
        if ($file===false) { throw new \RuntimeException('Neue Datei konnte nicht angelegt werden.'); }
        try { if (fwrite($file,$contents)!==strlen($contents)) { throw new \RuntimeException('Datei konnte nicht vollständig geschrieben werden.'); } }
        finally { fclose($file); }
        chmod($path,$mode);
    }

    private function atomicWrite(string $path, string $contents, int $mode): void
    {
        $temporary=tempnam(dirname($path),'.corps-deploy-');
        if ($temporary===false) { throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.'); }
        try {
            if (file_put_contents($temporary,$contents)!==strlen($contents) || !chmod($temporary,$mode) || !rename($temporary,$path)) { throw new \RuntimeException('Datei konnte nicht atomar veröffentlicht werden.'); }
        } finally { if (is_file($temporary)) { unlink($temporary); } }
    }

    private function publicDirectory(string $directory, string $webroot): void
    {
        $parts=explode('/',substr($directory,strlen($webroot)+1)); $current=$webroot;
        foreach ($parts as $part) {
            $current.='/'.$part;
            if (is_link($current)) { throw new \RuntimeException('Symlink im öffentlichen Zielpfad.'); }
            $this->directory($current,0755);
        }
    }

    private function inside(string $path, string $parent): bool { return $path===$parent || str_starts_with($path,$parent.'/'); }
}
