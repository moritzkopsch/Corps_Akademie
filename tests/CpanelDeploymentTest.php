<?php
declare(strict_types=1);
namespace Corpsakademie\Tests;

use Corpsakademie\Deployment\{Deployment,Runtime};
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__).'/deployment/cpanel/runtime.php';
require_once dirname(__DIR__).'/deployment/cpanel/deployment.php';

final class CpanelDeploymentTest extends TestCase
{
    private string $directory;
    private string $source;
    private string $base;
    private string $webroot;
    private string $root;
    private ?string $schema = null;

    protected function setUp(): void
    {
        $this->root=dirname(__DIR__);
        $this->directory=realpath(sys_get_temp_dir()).'/corps-cpanel-'.bin2hex(random_bytes(8));
        $this->source=$this->directory.'/source'; $this->base=$this->directory.'/private'; $this->webroot=$this->directory.'/webroot';
        mkdir($this->source.'/public',0700,true); mkdir($this->webroot,0755);
        copy($this->root.'/public/.htaccess',$this->source.'/public/.htaccess');
    }

    private function prepare(): Deployment
    {
        $deployment=new Deployment($this->source,$this->base);
        $deployment->initialize();
        file_put_contents($this->base.'/shared/deploy.json',json_encode(['webroot'=>$this->webroot,'composer'=>$this->directory.'/composer.php','replace_existing_site'=>false]));
        file_put_contents($this->directory.'/composer.php','<?php exit(0);');
        $database=$this->database();
        $env=file_get_contents($this->base.'/shared/.env');
        $env=str_replace(
            ['https://CHANGE-ME.invalid','smtps://CHANGE-ME:CHANGE-ME@CHANGE-ME.invalid:465','CHANGE-ME@CHANGE-ME.invalid','APP_DB_HOST=localhost','APP_DB_PORT=5432','APP_DB_NAME=CHANGE-ME','APP_DB_USER=CHANGE-ME','APP_DB_PASSWORD="CHANGE-ME"'],
            ['https://akademie.example.test','smtps://test:test@smtp.example.test:465','system@example.test','APP_DB_HOST='.$database['host'],'APP_DB_PORT='.$database['port'],'APP_DB_NAME='.$database['name'],'APP_DB_USER='.$database['user'],'APP_DB_PASSWORD="'.str_replace(['\\','"'],['\\\\','\\"'],$database['password']).'"'],
            $env
        );
        file_put_contents($this->base.'/shared/.env',$env);
        return $deployment;
    }

    private function database(): array
    {
        return [
            'host'=>(string)(getenv('TEST_DB_HOST')?:'127.0.0.1'),
            'port'=>(string)(getenv('TEST_DB_PORT')?:'5432'),
            'name'=>(string)(getenv('TEST_DB_NAME')?:'corpsakademie_test'),
            'user'=>(string)(getenv('TEST_DB_USER')?:'corpsakademie'),
            'password'=>(string)(getenv('TEST_DB_PASSWORD')?:'korps-test-password'),
        ];
    }

    private function pdo(array $database): \PDO
    {
        return new \PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s',$database['host'],$database['port'],$database['name']),$database['user'],$database['password'],[
            \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC,
        ]);
    }

    // The complete pipeline runs db:migrate and doctor in the new release and therefore needs a real PostgreSQL schema.
    private function isolatedSchema(): array
    {
        $database=$this->database();
        if (getenv('TEST_DB_USER')===false || getenv('TEST_DB_PASSWORD')===false) {
            self::markTestSkipped('PostgreSQL-Testdatenbank fehlt. TEST_DB_NAME, TEST_DB_USER und TEST_DB_PASSWORD setzen.');
        }
        $this->schema='corps_cpanel_'.bin2hex(random_bytes(4));
        try {
            $pdo=$this->pdo($database);
            $pdo->exec('DROP SCHEMA IF EXISTS '.$this->schema.' CASCADE');
            $pdo->exec('CREATE SCHEMA '.$this->schema);
        } catch (\PDOException $error) {
            self::markTestSkipped('Die PostgreSQL-Testdatenbank ist nicht erreichbar: '.$error->getMessage());
        }
        // libpq übernimmt PGOPTIONS für jede Verbindung, also auch für die Kindprozesse des Deployments.
        putenv('PGOPTIONS=-c search_path='.$this->schema);
        return $database;
    }

    private function assertMigrated(array $database): void
    {
        $pdo=$this->pdo($database);
        $tables=$pdo->query('SELECT count(*) FROM information_schema.tables WHERE table_schema='.$pdo->quote((string)$this->schema)." AND table_name IN ('schema_migrations','seminars','users')")->fetchColumn();
        self::assertSame(3,(int)$tables);
    }

    public function testInitIsIdempotentAndNeverReplacesKeyOrExistingData(): void
    {
        $deployment=$this->prepare(); $env=file_get_contents($this->base.'/shared/.env');
        file_put_contents($this->base.'/shared/storage/keep.txt','persistent');
        $deployment->initialize();
        self::assertSame($env,file_get_contents($this->base.'/shared/.env'));
        self::assertSame('persistent',file_get_contents($this->base.'/shared/storage/keep.txt'));
        self::assertSame(0600,fileperms($this->base.'/shared/.env')&0777);
        self::assertFileDoesNotExist($this->source.'/.env');
    }

    public function testInitRefusesToCreateNewKeyWhenCheckoutHasConfiguration(): void
    {
        file_put_contents($this->source.'/.env','existing secret');
        try { (new Deployment($this->source,$this->base))->initialize(); self::fail('Existing configuration ignored'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('bereits',$error->getMessage()); }
        self::assertDirectoryDoesNotExist($this->base);
    }

    public function testCheckIsReadonlyAndRejectsPlaceholderAndMissingStorage(): void
    {
        $deployment=$this->prepare();
        self::assertSame($this->webroot,$deployment->check()['webroot']);
        self::assertSame([],array_values(array_diff(scandir($this->webroot),['.','..'])));
        rmdir($this->base.'/releases');
        try { $deployment->check(); self::fail('Missing directory accepted'); }
        catch (\RuntimeException) {}
        self::assertDirectoryDoesNotExist($this->base.'/releases');
    }

    public function testCheckRejectsExistingWebsiteAndSensitiveFilesWithoutChangingThem(): void
    {
        $deployment=$this->prepare();
        file_put_contents($this->webroot.'/index.php','<?php echo "old website";');
        try { $deployment->check(); self::fail('Existing site accepted'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('andere Website',$error->getMessage()); }
        self::assertSame('<?php echo "old website";',file_get_contents($this->webroot.'/index.php'));
        unlink($this->webroot.'/index.php'); file_put_contents($this->webroot.'/.env','secret');
        $this->expectException(\RuntimeException::class); $deployment->check();
    }

    public function testManagedHtaccessPreservesCpanelHandlerAndRejectsOldRouting(): void
    {
        $deployment=$this->prepare();
        $handler="<IfModule mime_module>\nAddHandler application/x-httpd-alt-php85 .php\n</IfModule>\n";
        $first=$deployment->htaccess($handler);
        self::assertStringContainsString($handler,$first);
        self::assertSame($first,$deployment->htaccess($first));
        self::assertSame(1,substr_count($first,'# BEGIN CORPSAKADEMIE CPANEL'));
        $this->expectException(\RuntimeException::class);
        $deployment->htaccess("RewriteEngine On\nRewriteRule . /old-index.php [L]\n");
    }

    public function testOnlyStaticAssetsAreAllowedAndSymlinksAreRejected(): void
    {
        $deployment=$this->prepare(); $assets=$this->directory.'/assets'; mkdir($assets);
        file_put_contents($assets.'/app.css','body{}');
        self::assertSame(['app.css'=>$assets.'/app.css'],$deployment->assetFiles($assets));
        symlink($this->base.'/shared/.env',$assets.'/secret.css');
        $this->expectException(\RuntimeException::class); $deployment->assetFiles($assets);
    }

    public function testWebProbeChecksNonceAndCleansUpOnFailure(): void
    {
        $this->prepare();
        $deployment=new Deployment($this->source,$this->base,static fn(string $url): array=>[200,'{"nonce":"wrong"}']);
        try { $deployment->webProbe($this->webroot,'https://akademie.example.test'); self::fail('Wrong nonce accepted'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('APP_URL',$error->getMessage()); }
        self::assertSame([],glob($this->webroot.'/corps-deploy-check-*.php'));
    }

    public function testProbeDoesNotTrustPort443AndEntryReturnsMaintenanceWithoutApp(): void
    {
        $deployment=$this->prepare();
        $probe=$this->directory.'/probe.php'; file_put_contents($probe,Runtime::probe('fixture',time()+120));
        $result=$this->process([PHP_BINARY,'-r','$_SERVER["SERVER_PORT"]=443; require $argv[1];',$probe]);
        self::assertSame(0,$result['code']); self::assertFalse(json_decode($result['out'],true)['https']);
        $entry=$this->directory.'/entry.php';file_put_contents($entry,$deployment->entry());
        file_put_contents($this->base.'/shared/maintenance.flag','maintenance');
        $result=$this->process([PHP_BINARY,$entry]);
        self::assertStringContainsString('gerade aktualisiert',$result['out']); self::assertSame('',$result['err']);
    }

    public function testHttpEntryAndCronRespectTheSameRuntimeLock(): void
    {
        $deployment=$this->prepare();
        $entry=$this->directory.'/entry.php'; file_put_contents($entry,$deployment->entry());
        $lock=fopen($this->base.'/shared/runtime.lock','r+'); flock($lock,LOCK_EX);
        try {
            $http=$this->process([PHP_BINARY,$entry]);
            self::assertStringContainsString('gerade aktualisiert',$http['out']);
            $cli=$this->process([PHP_BINARY,$this->root.'/scripts/cpanel-console.php','--base='.$this->base,'doctor']);
            self::assertSame(75,$cli['code']); self::assertStringContainsString('übersprungen',$cli['err']);
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }

    public function testRuntimeRejectsAMissingPostgresDriver(): void
    {
        $data=Runtime::inspect(); $data['pgsql']=null;
        $this->expectException(\RuntimeException::class); Runtime::validate($data);
    }

    public function testCompleteDeploymentBuildsPrivateReleaseAndKeepsSecretsOutsideWebroot(): void
    {
        [$deployment,$database]=$this->pipeline();
        $env=file_get_contents($this->base.'/shared/.env');
        $deployment->deploy();
        self::assertTrue(is_link($this->base.'/current'));
        self::assertSame($env,file_get_contents($this->base.'/shared/.env'));
        self::assertStringContainsString(Deployment::MARKER,file_get_contents($this->webroot.'/index.php'));
        self::assertFileExists($this->webroot.'/assets/website.css');
        self::assertFileDoesNotExist($this->webroot.'/.env'); self::assertDirectoryDoesNotExist($this->webroot.'/vendor');
        self::assertFileDoesNotExist($this->base.'/shared/maintenance.flag');
        self::assertDirectoryExists($this->base.'/shared/storage');
        $this->assertMigrated($database);
    }

    public function testFailureAfterMigrationStaysInMaintenanceAndDoesNotSwitchRelease(): void
    {
        [$deployment,$database]=$this->pipeline(true);
        mkdir($this->base.'/releases/previous'); symlink($this->base.'/releases/previous',$this->base.'/current');
        try { $deployment->deploy(); self::fail('Synthetic doctor failure ignored'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Deployment-Schritt',$error->getMessage()); }
        self::assertSame($this->base.'/releases/previous',realpath($this->base.'/current'));
        self::assertFileExists($this->base.'/shared/maintenance.flag');
        $this->assertMigrated($database);
    }

    private function pipeline(bool $failDoctor = false): array
    {
        $database=$this->isolatedSchema();
        $this->prepare();
        foreach (['app','bin','database','public','resources','deployment'] as $directory) { $this->copyDirectory($this->root.'/'.$directory,$this->source.'/'.$directory); }
        copy($this->root.'/composer.json',$this->source.'/composer.json');
        copy($this->root.'/composer.lock',$this->source.'/composer.lock');
        copy($this->root.'/.gitignore',$this->source.'/.gitignore');
        if ($failDoctor) {
            $console=file_get_contents($this->source.'/bin/console');
            $console=str_replace("declare(strict_types=1);","declare(strict_types=1);\nif ((\$argv[1]??'')==='doctor') { exit(19); }",$console);
            file_put_contents($this->source.'/bin/console',$console);
        }
        $autoload=var_export($this->root.'/vendor/autoload.php',true);
        file_put_contents($this->directory.'/composer.php',"<?php if ((\$argv[1]??'')==='install') { mkdir(getcwd().'/vendor'); file_put_contents(getcwd().'/vendor/autoload.php',".var_export('<?php require '.$autoload.';',true)."); } exit(0);");
        foreach ([['git','init','-q'],['git','add','.'],['git','-c','user.name=Deployment Test','-c','user.email=test@example.test','-c','commit.gpgsign=false','-c','core.hooksPath=/dev/null','commit','-qm','Test fixture']] as $command) {
            $result=$this->process($command,$this->source); self::assertSame(0,$result['code'],$result['err']);
        }
        return [new Deployment($this->source,$this->base,function(string $url): array {
            $file=$this->webroot.parse_url($url,PHP_URL_PATH);
            $result=$this->process([PHP_BINARY,'-r','$_SERVER["HTTPS"]="on"; require $argv[1];',$file]);
            self::assertSame(0,$result['code'],$result['err']);
            return [200,$result['out']];
        }),$database];
    }

    private function process(array $command, ?string $directory = null): array
    {
        $process=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$directory);
        self::assertIsResource($process);
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);
        return ['code'=>proc_close($process),'out'=>$out,'err'=>$err];
    }

    private function copyDirectory(string $source, string $target): void
    {
        if (!is_dir($target)) { mkdir($target,0700,true); }
        foreach (new \FilesystemIterator($source,\FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isDir()) { $this->copyDirectory($file->getPathname(),$target.'/'.$file->getFilename()); }
            else { copy($file->getPathname(),$target.'/'.$file->getFilename()); }
        }
    }

    protected function tearDown(): void
    {
        if ($this->schema!==null) {
            try { $this->pdo($this->database())->exec('DROP SCHEMA IF EXISTS '.$this->schema.' CASCADE'); } catch (\Throwable) {}
            $this->schema=null;
        }
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) { $file->isDir() && !$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname()); }
        rmdir($this->directory);
    }
}
