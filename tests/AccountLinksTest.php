<?php
declare(strict_types=1);
namespace Corpsakademie\Tests;

use Corpsakademie\Infrastructure\Crypto;
use Corpsakademie\Tests\Support\Fixture;
use OTPHP\TOTP;

final class AccountLinksTest extends Fixture
{
    private function setupToken(): string
    {
        $link=$this->c->links->setupLink(); parse_str(parse_url($link,PHP_URL_FRAGMENT),$parts); return $parts['token'];
    }

    public function testCompleteFirstAccountFlowRequiresEmailThenMfa(): void
    {
        $token=$this->setupToken(); $this->request('GET','/einrichten');
        $response=$this->request('POST','/einrichten',[
            '_csrf'=>$this->csrf(),'token'=>$token,'email'=>'owner@example.test','name'=>'Owner',
            'password'=>self::PASSWORD,'password_confirm'=>self::PASSWORD,
        ]);
        self::assertSame('/email-bestaetigung',$response->headers->get('Location'));
        self::assertSame(1,(int)$this->c->db->run('SELECT count(*) FROM users')->fetchColumn());
        self::assertSame('/email-bestaetigung',$this->request('GET','/admin')->headers->get('Location'));
        $this->c->mail->work(); $verification=$this->deliveredToken();
        // A scanner GET neither consumes the token nor confirms the address.
        $this->request('GET','/email-bestaetigen?token='.$verification);
        self::assertNull($this->c->accounts->findByEmail('owner@example.test')['email_verified_at']);
        $response=$this->request('POST','/email-bestaetigen',['_csrf'=>$this->csrf(),'token'=>$verification]);
        self::assertSame('/sicherheit/einrichten',$response->headers->get('Location'));
        $this->request('GET','/sicherheit/einrichten'); $secret=$this->sessionData()['mfa_secret'];
        $response=$this->request('POST','/sicherheit/einrichten',['_csrf'=>$this->csrf(),'code'=>TOTP::createFromSecret($secret,$this->clock)->now()]);
        self::assertSame('/sicherheit/wiederherstellung',$response->headers->get('Location'));
        self::assertSame(200,$this->request('GET','/admin')->getStatusCode());
        self::assertSame(404,$this->request('GET','/einrichten')->getStatusCode());
        $this->expectException(\DomainException::class); $this->c->links->setupLink();
    }

    public function testReissuedAndExpiredSetupTokensFailWithoutCreatingAnAccount(): void
    {
        $first=$this->setupToken(); $second=$this->setupToken();
        try { $this->c->links->setup($first,'owner@example.test','Owner',self::PASSWORD); self::fail('Old token accepted'); }
        catch (\DomainException) { self::assertSame(0,(int)$this->c->db->run('SELECT count(*) FROM users')->fetchColumn()); }
        $this->clock->advance(1800);
        $this->expectException(\DomainException::class); $this->c->links->setup($second,'owner@example.test','Owner',self::PASSWORD);
    }

    public function testVerificationTokenIsHashedAndCannotBeUsedTwice(): void
    {
        $user=$this->c->accounts->create('pending@example.test','Pending',self::PASSWORD,'instructor');
        $this->c->links->queueVerification($user); $this->c->mail->work(); $token=$this->deliveredToken();
        $stored=$this->c->db->one('SELECT * FROM email_verification_tokens');
        self::assertSame(Crypto::hash($token),$stored['token_hash']);
        self::assertStringNotContainsString($token,json_encode($stored));
        $this->c->links->confirmEmail($token);
        self::assertNotNull($this->c->accounts->find((int)$user['id'])['email_verified_at']);
        $this->expectException(\DomainException::class); $this->c->links->confirmEmail($token);
    }

    public function testPasswordResetRevokesSessionsAndRequiresTheNewPassword(): void
    {
        $user=$this->account(); $this->login();
        $this->c->links->requestReset($user['email'],'127.0.0.1');
        $before=$this->c->db->one('SELECT * FROM notification_outbox');
        self::assertSame('queued',$before['status']);
        self::assertStringNotContainsString('#token=',$before['secret_payload_ciphertext']);
        $this->mailer->onSend=function(): void { self::assertFalse($this->c->db->inTransaction()); };
        self::assertSame(1,$this->c->mail->work()['submitted']); $token=$this->deliveredToken();
        self::assertNull($this->c->db->one('SELECT * FROM notification_outbox')['secret_payload_ciphertext']);
        $this->c->links->reset($token,'A new testing passphrase 2026!');
        self::assertSame('/anmelden',$this->request('GET','/konto')->headers->get('Location'));
        self::assertSame(422,$this->login()->getStatusCode());
        self::assertSame('/portal',$this->login($user['email'],'A new testing passphrase 2026!')->headers->get('Location'));
        $this->expectException(\DomainException::class); $this->c->links->reset($token,'Another test-only passphrase 2026!');
    }

    public function testExpiredResetAndCrossPurposeTokensAreRejected(): void
    {
        $user=$this->account(); $this->c->links->requestReset($user['email'],'127.0.0.1'); $this->c->mail->work();
        $token=$this->deliveredToken();
        try { $this->c->links->confirmEmail($token); self::fail('Reset token confirmed email directly'); } catch (\DomainException) {}
        $this->clock->advance(1800);
        $this->expectException(\DomainException::class); $this->c->links->reset($token,'A new testing passphrase 2026!');
    }

    public function testResetDoesNotBypassExistingMfa(): void
    {
        $user=$this->account('admin@example.test','admin');
        $secret=$this->c->mfa->newSecret();
        $this->c->mfa->enroll((int)$user['id'],$secret,TOTP::createFromSecret($secret,$this->clock)->now());
        $this->c->links->requestReset($user['email'],'127.0.0.1'); $this->c->mail->work();
        $this->c->links->reset($this->deliveredToken(),'A new testing passphrase 2026!');
        self::assertSame('/sicherheit/code',$this->login($user['email'],'A new testing passphrase 2026!')->headers->get('Location'));
        self::assertSame('/sicherheit/code',$this->request('GET','/admin')->headers->get('Location'));
    }

    public function testForgotPasswordResponseDoesNotRevealAccounts(): void
    {
        $this->account(); $this->request('GET','/passwort-vergessen');
        $unknown=$this->request('POST','/passwort-vergessen',['_csrf'=>$this->csrf(),'email'=>'unknown@example.test']);
        $known=$this->request('POST','/passwort-vergessen',['_csrf'=>$this->csrf(),'email'=>'referent@example.test']);
        self::assertSame($unknown->getStatusCode(),$known->getStatusCode()); self::assertSame($unknown->getContent(),$known->getContent());
    }

    public function testOutboxCancelsOldLinksBlocksRealRecipientsAndDoesNotRetryAmbiguousSends(): void
    {
        $user=$this->account();
        $this->c->links->requestReset($user['email'],'127.0.0.1'); $this->c->links->requestReset($user['email'],'127.0.0.1');
        $result=$this->c->mail->work(); self::assertSame(1,$result['cancelled']); self::assertSame(1,$result['submitted']);
        $real=$this->account('person@example.com'); $this->c->links->requestReset($real['email'],'127.0.0.1');
        self::assertSame('blocked',$this->c->db->one('SELECT status FROM notification_outbox WHERE recipient=?',[$real['email']])['status']);
        $this->c->links->requestReset($user['email'],'127.0.0.1'); $this->mailer->fail=true;
        self::assertSame(1,$this->c->mail->work()['ambiguous']); $calls=$this->mailer->calls;
        $this->c->mail->work(); self::assertSame($calls,$this->mailer->calls);
    }

    public function testExpiredWorkerLeaseNeverCausesBlindResend(): void
    {
        $user=$this->account(); $this->c->links->requestReset($user['email'],'127.0.0.1');
        $this->c->db->run("UPDATE notification_outbox SET status='sending',lease_token='previous-worker',lease_until=?",[$this->clock->now()->getTimestamp()-1]);
        self::assertSame(1,$this->c->mail->work()['ambiguous']); self::assertSame(0,$this->mailer->calls);
        self::assertSame('ambiguous',$this->c->db->one('SELECT status FROM notification_outbox')['status']);
    }
}
