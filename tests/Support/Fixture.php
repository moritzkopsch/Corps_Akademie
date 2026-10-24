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

    protected function setUp(): void
    {
        $this->root=dirname(__DIR__,2);
        $this->directory=sys_get_temp_dir().'/corpsakademie-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory,0700);
        $this->clock=new TestClock(); $this->mailer=new TestMailer();
        $this->c=new Container(new Config($this->root,$this->values()),$this->clock,$this->mailer);
        (new Migrator($this->c->db,$this->root.'/database/migrations'))->migrate();
        $this->app=new Application($this->c);
    }

    protected function values(array $overrides=[]): array
    {
        return array_replace([
            'APP_ENV'=>'test','APP_URL'=>'http://127.0.0.1:8080','APP_KEY'=>base64_encode(random_bytes(32)),
            'APP_DB_PATH'=>$this->directory.'/database.sqlite','APP_STORAGE_PATH'=>$this->directory.'/storage',
            'MAILER_DSN'=>'smtp://127.0.0.1:1025','MAIL_FROM'=>'system@example.test',
        ],$overrides);
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
        unset($this->app,$this->c);
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) { $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname()); }
        rmdir($this->directory);
    }
}
