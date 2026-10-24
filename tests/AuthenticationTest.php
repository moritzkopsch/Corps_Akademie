<?php
declare(strict_types=1);
namespace Corpsakademie\Tests;

use Corpsakademie\Http\SessionStore;
use Corpsakademie\Infrastructure\Crypto;
use Corpsakademie\Tests\Support\Fixture;

final class AuthenticationTest extends Fixture
{
    public function testLoginRotatesCookieAndStoresOnlyHashAndCiphertext(): void
    {
        $this->account(); $this->request('GET','/anmelden');
        $before=$this->cookies[SessionStore::COOKIE]; $csrf=$this->csrf();
        $response=$this->request('POST','/anmelden',['email'=>'REFERENT@example.test','password'=>self::PASSWORD,'_csrf'=>$csrf]);
        self::assertSame(303,$response->getStatusCode()); self::assertSame('/portal',$response->headers->get('Location'));
        $after=$this->cookies[SessionStore::COOKIE]; self::assertNotSame($before,$after);
        $row=$this->c->db->one('SELECT * FROM sessions WHERE session_hash=?',[Crypto::hash($after)]);
        self::assertNotSame($after,$row['session_hash']); self::assertStringNotContainsString($this->csrf(),$row['payload_ciphertext']);
        self::assertNotNull($this->c->db->one('SELECT revoked_at FROM sessions WHERE session_hash=?',[Crypto::hash($before)])['revoked_at']);
        $cookie=$response->headers->getCookies()[0]; self::assertTrue($cookie->isHttpOnly()); self::assertSame('lax',$cookie->getSameSite());
        self::assertSame(200,$this->request('GET','/portal')->getStatusCode());
    }

    public function testWrongPasswordUnknownAccountAndSqlInjectionDoNotAuthenticate(): void
    {
        $this->account();
        foreach (['referent@example.test','unknown@example.test',"' OR 1=1 --"] as $email) {
            $response=$this->login($email,'invalid');
            self::assertSame(422,$response->getStatusCode());
            self::assertStringContainsString('E-Mail-Adresse oder Passwort ist nicht korrekt.',$response->getContent());
            self::assertSame(303,$this->request('GET','/portal')->getStatusCode());
        }
    }

    public function testCsrfAndForeignOriginAreRejected(): void
    {
        $this->account(); $this->request('GET','/anmelden');
        $data=['email'=>'referent@example.test','password'=>self::PASSWORD];
        self::assertSame(403,$this->request('POST','/anmelden',$data)->getStatusCode());
        $data['_csrf']=$this->csrf();
        self::assertSame(403,$this->request('POST','/anmelden',$data,['HTTP_ORIGIN'=>'https://attacker.example'])->getStatusCode());
        self::assertSame(303,$this->request('POST','/anmelden',$data)->getStatusCode());
    }

    public function testInstructorCannotAccessAdminOrAnotherProfile(): void
    {
        $this->account(); $other=$this->account('private@example.test','instructor','Private Person');
        $this->login();
        self::assertSame(403,$this->request('GET','/admin')->getStatusCode());
        self::assertSame(403,$this->request('GET','/admin/benutzer')->getStatusCode());
        $own=$this->request('GET','/portal?user_id='.$other['id']);
        self::assertStringContainsString('referent@example.test',$own->getContent());
        self::assertStringNotContainsString('private@example.test',$own->getContent());
        self::assertStringNotContainsString('Private Person',$own->getContent());
    }

    public function testAdminAndMixedRolesRequireMfaBeforeAnyPortalAccess(): void
    {
        $user=$this->account('admin@example.test','admin');
        $this->c->db->run("INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code='instructor'",[$user['id']]);
        self::assertSame('/sicherheit/einrichten',$this->login('admin@example.test')->headers->get('Location'));
        self::assertSame('/sicherheit/einrichten',$this->request('GET','/admin')->headers->get('Location'));
        self::assertSame('/sicherheit/einrichten',$this->request('GET','/portal')->headers->get('Location'));
    }

    public function testEmailMustBeVerifiedBeforePortalAccess(): void
    {
        $this->c->accounts->create('pending@example.test','Pending',self::PASSWORD,'instructor');
        self::assertSame('/email-bestaetigung',$this->login('pending@example.test')->headers->get('Location'));
        self::assertSame('/email-bestaetigung',$this->request('GET','/portal')->headers->get('Location'));
    }

    public function testLogoutRequiresPostAndRevokesTheSession(): void
    {
        $this->account(); $this->login(); $old=$this->cookies[SessionStore::COOKIE];
        self::assertSame(405,$this->request('GET','/abmelden')->getStatusCode());
        self::assertSame(303,$this->request('POST','/abmelden',['_csrf'=>$this->csrf()])->getStatusCode());
        $this->cookies[SessionStore::COOKIE]=$old;
        self::assertSame('/anmelden',$this->request('GET','/konto')->headers->get('Location'));
    }

    public function testExpiredAndDisabledSessionsCannotBeUsed(): void
    {
        $user=$this->account(); $this->login();
        $this->clock->advance(1801);
        self::assertSame('/anmelden',$this->request('GET','/konto')->headers->get('Location'));
        $this->login(); $this->c->accounts->disable((int)$user['id']);
        self::assertSame('/anmelden',$this->request('GET','/portal')->headers->get('Location'));
        self::assertSame(422,$this->login()->getStatusCode());
    }

    public function testRemovedRoleTakesEffectOnTheNextRequest(): void
    {
        $user=$this->account(); $this->login();
        $this->c->db->run('DELETE FROM user_roles WHERE user_id=?',[$user['id']]);
        self::assertSame(403,$this->request('GET','/portal')->getStatusCode());
    }

    public function testLoginRateLimitRecoversAfterItsWindow(): void
    {
        $this->account();
        for ($i=0;$i<8;$i++) { $this->login('referent@example.test','wrong'); }
        self::assertStringContainsString('Zu viele Anmeldeversuche.',$this->login()->getContent());
        $this->clock->advance(901);
        self::assertSame(303,$this->login()->getStatusCode());
        $buckets=$this->c->db->run('SELECT subject_hmac FROM rate_limit_buckets')->fetchAll();
        self::assertStringNotContainsString('referent@example.test',json_encode($buckets));
    }

    public function testEscapingAndSecurityHeaders(): void
    {
        $this->account('referent@example.test','instructor','<script>alert(1)</script>'); $this->login();
        $response=$this->request('GET','/konto');
        self::assertSame(200,$response->getStatusCode());
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;',$response->getContent());
        self::assertStringNotContainsString('<script>alert(1)</script>',$response->getContent());
        self::assertStringContainsString("frame-ancestors 'none'",$response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        self::assertSame('same-origin',$response->headers->get('Referrer-Policy'));
        self::assertSame(404,$this->request('GET','/.env')->getStatusCode());
        self::assertSame(400,$this->request('GET','/',[],['HTTP_HOST'=>'attacker.example'])->getStatusCode());
    }

    public function testLastActiveSuperadminCannotBeDisabled(): void
    {
        $user=$this->account('super@example.test','superadmin');
        $this->expectException(\DomainException::class); $this->c->accounts->disable((int)$user['id']);
    }

    public function testAdministrativePermissionRequiresMfaEvenWithCustomRole(): void
    {
        $user=$this->account();
        $this->c->db->run("INSERT INTO roles(code,label,protected) VALUES('custom','Custom',0)");
        $role=(int)$this->c->db->pdo->lastInsertId();
        $this->c->db->run('INSERT INTO user_roles(user_id,role_id) VALUES(?,?)',[$user['id'],$role]);
        $this->c->db->run("INSERT INTO role_permissions(role_id,permission_id) SELECT ?,id FROM permissions WHERE code='admin.access'",[$role]);
        self::assertSame('/sicherheit/einrichten',$this->login()->headers->get('Location'));
        self::assertSame('/sicherheit/einrichten',$this->request('GET','/admin')->headers->get('Location'));
    }

    public function testProductionUsesSecureCookiesAndRejectsHttp(): void
    {
        $config=new \Corpsakademie\Infrastructure\Config($this->root,$this->values([
            'APP_ENV'=>'production','APP_URL'=>'https://akademie.example.test','MAILER_DSN'=>'smtps://mail.example.test:465',
        ]));
        $this->c=new \Corpsakademie\Container($config,$this->clock,$this->mailer);
        $this->app=new \Corpsakademie\Http\Application($this->c);
        $this->account(); $response=$this->login();
        self::assertTrue($response->headers->getCookies()[0]->isSecure());
        self::assertSame('max-age=31536000',$response->headers->get('Strict-Transport-Security'));
        $http=\Symfony\Component\HttpFoundation\Request::create('http://akademie.example.test/konto');
        self::assertSame(400,$this->app->handle($http)->getStatusCode());
    }
}
