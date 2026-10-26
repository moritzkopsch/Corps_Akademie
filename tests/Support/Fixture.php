<?php
declare(strict_types=1);
namespace Corpsakademie\Tests\Support;

use Corpsakademie\Container;
use Corpsakademie\Http\{Application,SessionStore};
use Corpsakademie\Infrastructure\{Config,Crypto,Migrator};
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\{Request,Response};

abstract class Fixture extends TestCase
{
    protected string $directory;
    protected string $root;
    protected Container $c;
    protected Application $app;
    protected TestClock $clock;
    protected TestMailer $mailer;
    protected array $cookies=[];
    protected const PASSWORD='Only for automated tests 2026!';
    private static ?string $schema = null;

    protected function setUp(): void
    {
        $this->root=dirname(__DIR__,2);
        $this->directory=sys_get_temp_dir().'/corpsakademie-test-'.bin2hex(random_bytes(8));
        $values=$this->values();
        if ($values['APP_DB_USER']==='' || $values['APP_DB_PASSWORD']==='') {
            $this->markTestSkipped('PostgreSQL-Testdatenbank fehlt. TEST_DB_NAME, TEST_DB_USER und TEST_DB_PASSWORD setzen.');
        }
        try { self::prepareSchema($values); }
        catch (\RuntimeException $error) { $this->markTestSkipped($error->getMessage()); }
        mkdir($this->directory,0700);
        $this->clock=new TestClock(); $this->mailer=new TestMailer();
        $this->c=new Container(new Config($this->root,$values),$this->clock,$this->mailer);
        (new Migrator($this->c->db,$this->root.'/database/migrations'))->migrate();
        $this->app=new Application($this->c);
    }

    protected function values(array $overrides=[]): array
    {
        return array_replace([
            'APP_ENV'=>'test','APP_URL'=>'http://127.0.0.1:8080','APP_KEY'=>base64_encode(random_bytes(32)),
            'APP_DB_HOST'=>(string)(getenv('TEST_DB_HOST')?:'127.0.0.1'),
            'APP_DB_PORT'=>(string)(getenv('TEST_DB_PORT')?:'5432'),
            'APP_DB_NAME'=>(string)(getenv('TEST_DB_NAME')?:'corpsakademie_test'),
            'APP_DB_USER'=>(string)(getenv('TEST_DB_USER')?:''),
            'APP_DB_PASSWORD'=>(string)(getenv('TEST_DB_PASSWORD')?:''),
            'APP_STORAGE_PATH'=>$this->directory.'/storage',
            'MAILER_DSN'=>'smtp://127.0.0.1:1025','MAIL_FROM'=>'system@example.test',
        ],$overrides);
    }

    protected static function dsn(array $values): string
    {
        return sprintf('pgsql:host=%s;port=%s;dbname=%s',$values['APP_DB_HOST'],$values['APP_DB_PORT'],$values['APP_DB_NAME']);
    }

    protected static function schemaName(): string
    {
        if (self::$schema === null) {
            self::$schema=(string)(getenv('TEST_DB_SCHEMA')?:'corps_test_'.bin2hex(random_bytes(4)));
        }
        return self::$schema;
    }

    private static function prepareSchema(array $values): void
    {
        $schema=self::schemaName();
        // libpq übernimmt PGOPTIONS für jede Verbindung, also auch für die Prozesse der Parallelitätstests.
        putenv('PGOPTIONS=-c search_path='.$schema);
        try {
            $pdo=new \PDO(self::dsn($values),$values['APP_DB_USER'],$values['APP_DB_PASSWORD'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
            $pdo->exec('CREATE SCHEMA '.$schema);
        } catch (\PDOException $error) {
            throw new \RuntimeException('Die PostgreSQL-Testdatenbank ist nicht erreichbar: '.$error->getMessage(),0,$error);
        }
    }

    protected function request(string $method,string $path,array $data=[],array $server=[]): Response
    {
        $request=Request::create($this->c->config->url.$path,$method,$data,$this->cookies,[],array_replace(['REMOTE_ADDR'=>'127.0.0.1'],$server));
        if (isset($server['HTTP_HOST'])) { $request->headers->set('Host',$server['HTTP_HOST']); }
        $response=$this->app->handle($request);
        foreach ($response->headers->getCookies() as $cookie) { $this->cookies[$cookie->getName()]=$cookie->getValue(); }
        return $response;
    }

    protected function csrf(): string { return $this->sessionData()['csrf']; }

    protected function sessionData(): array
    {
        $row=$this->c->db->one('SELECT * FROM sessions WHERE session_hash=?',[Crypto::hash($this->cookies[SessionStore::COOKIE])]);
        return json_decode($this->c->crypto->decrypt($row['payload_ciphertext'],'session',$row['key_id']),true,512,JSON_THROW_ON_ERROR);
    }

    protected function account(string $email='referent@example.test',string $role='instructor',string $name='Test Referent'): array
    {
        return $this->c->accounts->create($email,$name,self::PASSWORD,$role,true);
    }

    protected function login(string $email='referent@example.test',string $password=self::PASSWORD): Response
    {
        $this->request('GET','/anmelden');
        return $this->request('POST','/anmelden',['email'=>$email,'password'=>$password,'_csrf'=>$this->csrf()]);
    }

    protected function deliveredToken(int $index=0): string
    {
        $text=$this->mailer->messages[$index]->getTextBody();
        self::assertSame(1,preg_match('/#token=([A-Za-z0-9_-]{43})/',$text,$matches));
        return $matches[1];
    }

    protected function tearDown(): void
    {
        if (isset($this->c)) {
            try { $this->c->db->pdo->exec('DROP SCHEMA IF EXISTS '.self::schemaName().' CASCADE'); } catch (\Throwable) {}
        }
        unset($this->app,$this->c);
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) { $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname()); }
        rmdir($this->directory);
    }
}
