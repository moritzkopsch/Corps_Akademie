<?php
declare(strict_types=1);
namespace Corpsakademie\Tests;

use Corpsakademie\Http\SessionStore;
use Corpsakademie\Infrastructure\Crypto;
use Corpsakademie\Tests\Support\Fixture;
use OTPHP\TOTP;

final class MfaTest extends Fixture
{
    private function enrollAdmin(): array
    {
        $user=$this->account('admin@example.test','admin'); $this->login('admin@example.test');
        $response=$this->request('GET','/sicherheit/einrichten');
        self::assertSame(200,$response->getStatusCode());
        self::assertStringContainsString('data:image/svg+xml;base64,',$response->getContent());
        $secret=$this->sessionData()['mfa_secret'];
        $code=TOTP::createFromSecret($secret,$this->clock)->now();
        $response=$this->request('POST','/sicherheit/einrichten',['_csrf'=>$this->csrf(),'code'=>$code]);
        self::assertSame(303,$response->getStatusCode());
        self::assertSame('/sicherheit/wiederherstellung',$response->headers->get('Location'));
        return [$user,$secret,$this->sessionData()['recovery_codes']];
    }

    public function testEnrollmentEncryptsSecretAndRecoveryCodesAreShownOnlyOnce(): void
    {
        [$user,$secret,$codes]=$this->enrollAdmin();
        self::assertCount(10,$codes);
        $factor=$this->c->mfa->factor((int)$user['id']);
        self::assertStringNotContainsString($secret,$factor['secret_ciphertext']);
        $stored=$this->c->db->run('SELECT code_hash FROM mfa_recovery_codes')->fetchAll();
        self::assertStringNotContainsString(str_replace('-','',$codes[0]),json_encode($stored));
        self::assertSame(200,$this->request('GET','/admin')->getStatusCode());
        $page=$this->request('GET','/sicherheit/wiederherstellung');
        self::assertStringContainsString($codes[0],$page->getContent());
        self::assertArrayNotHasKey('recovery_codes',$this->sessionData());
        self::assertSame(303,$this->request('GET','/sicherheit/wiederherstellung')->getStatusCode());
    }

    public function testTotpCannotBeReplayedAndNewCodeRotatesTheSession(): void
    {
        [$user,$secret]=$this->enrollAdmin();
        $used=TOTP::createFromSecret($secret,$this->clock)->now();
        $this->request('POST','/abmelden',['_csrf'=>$this->csrf()]);
        self::assertSame('/sicherheit/code',$this->login('admin@example.test')->headers->get('Location'));
        self::assertSame('/sicherheit/code',$this->request('GET','/admin')->headers->get('Location'));
        self::assertSame(422,$this->request('POST','/sicherheit/code',['_csrf'=>$this->csrf(),'code'=>$used])->getStatusCode());
        $before=$this->cookies[SessionStore::COOKIE]; $this->clock->advance(30);
        $code=TOTP::createFromSecret($secret,$this->clock)->now();
        $response=$this->request('POST','/sicherheit/code',['_csrf'=>$this->csrf(),'code'=>$code]);
        self::assertSame('/admin',$response->headers->get('Location'));
        self::assertNotSame($before,$this->cookies[SessionStore::COOKIE]);
        self::assertSame(200,$this->request('GET','/admin')->getStatusCode());
    }

    public function testRecoveryCodeWorksOnlyOnce(): void
    {
        [$user,$secret,$codes]=$this->enrollAdmin();
        $this->request('POST','/abmelden',['_csrf'=>$this->csrf()]); $this->login('admin@example.test');
        self::assertSame('/admin',$this->request('POST','/sicherheit/code',['_csrf'=>$this->csrf(),'code'=>$codes[0]])->headers->get('Location'));
        $this->request('POST','/abmelden',['_csrf'=>$this->csrf()]); $this->login('admin@example.test');
        self::assertSame(422,$this->request('POST','/sicherheit/code',['_csrf'=>$this->csrf(),'code'=>$codes[0]])->getStatusCode());
        self::assertSame('/admin',$this->request('POST','/sicherheit/code',['_csrf'=>$this->csrf(),'code'=>$codes[1]])->headers->get('Location'));
    }

    public function testRevokedSessionCannotBeElevatedAfterPasswordReset(): void
    {
        [$user,$secret]=$this->enrollAdmin();
        $this->request('POST','/abmelden',['_csrf'=>$this->csrf()]); $this->login('admin@example.test');
        $request=\Symfony\Component\HttpFoundation\Request::create('http://127.0.0.1:8080/',cookies:$this->cookies);
        $pending=$this->c->sessions->load($request);
        $this->c->db->run('UPDATE sessions SET revoked_at=? WHERE session_hash=?',[$this->clock->now()->getTimestamp(),Crypto::hash($pending->token)]);
        $this->expectException(\DomainException::class);
        $this->c->sessions->rotate($pending,(int)$user['id'],['mfa_verified'=>true]);
    }

    public function testEnrollmentCannotBeOverwritten(): void
    {
        [$user]=$this->enrollAdmin();
        $new=$this->c->mfa->newSecret();
        $code=TOTP::createFromSecret($new,$this->clock)->now();
        $this->expectException(\DomainException::class);
        $this->c->mfa->enroll((int)$user['id'],$new,$code);
    }
}
