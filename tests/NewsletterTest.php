<?php
declare(strict_types=1);
namespace Corpsakademie\Tests;

use Corpsakademie\Container;
use Corpsakademie\Infrastructure\Config;
use Corpsakademie\Tests\Support\Fixture;
use OTPHP\TOTP;

final class NewsletterTest extends Fixture
{
    private function subscriber(string $email='reader@example.test',string $state='active'): int
    {
        return (int)$this->c->db->run('INSERT INTO newsletter_subscribers(email,email_key,state,consent_version,requested_at,confirmed_at) VALUES(?,?,?,?,?,?) RETURNING id',[$email,$email,$state,'test-consent',$this->clock->timestamp,$state==='active'?$this->clock->timestamp:null])->fetchColumn();
    }
    private function draft(int $actor): int { return $this->c->newsletter->save($actor,['subject'=>'Impulse für Ihr Corps','body'=>'Gemeinsam weiterkommen.'],null); }
    private function admin(): int { return (int)$this->account('admin@example.test','admin')['id']; }

    public function testQueueSnapshotsOnlyConfirmedSubscribersAndIsNotRepeatable(): void
    {
        $admin=$this->admin(); $this->subscriber(); $this->subscriber('pending@example.test','pending'); $this->subscriber('unsubscribed@example.test','unsubscribed');
        $id=$this->draft($admin); $this->c->newsletter->queue($admin,$id,1,true);
        $this->subscriber('later@example.test');
        self::assertSame(1,(int)$this->c->db->run('SELECT COUNT(*) FROM newsletter_deliveries')->fetchColumn());
        self::assertSame(1,$this->c->newsletter->work()['submitted']);
        self::assertSame(0,$this->c->newsletter->work()['submitted']);
        self::assertCount(1,$this->mailer->messages);
        self::assertSame('reader@example.test',$this->mailer->messages[0]->getTo()[0]->getAddress());
        $this->expectException(\DomainException::class); $this->c->newsletter->queue($admin,$id,1,true);
    }

    public function testAbandonedConsentAndCancelledCampaignAreNotSent(): void
    {
        $admin=$this->admin(); $subscriber=$this->subscriber(); $id=$this->draft($admin);
        $this->c->newsletter->queue($admin,$id,1,true);
        $this->c->db->run("UPDATE newsletter_subscribers SET state='unsubscribed' WHERE id=?",[$subscriber]);
        self::assertSame(1,$this->c->newsletter->work()['cancelled']);
        $this->c->db->run("UPDATE newsletter_subscribers SET state='active' WHERE id=?",[$subscriber]);
        $id=$this->draft($admin); $this->c->newsletter->queue($admin,$id,1,true);
        $this->c->db->run('UPDATE newsletter_subscribers SET subscription_version=subscription_version+1 WHERE id=?',[$subscriber]);
        self::assertSame(1,$this->c->newsletter->work()['cancelled']);
        $id=$this->draft($admin); $this->c->newsletter->queue($admin,$id,1,true); $this->c->newsletter->cancel($admin,$id);
        self::assertSame(0,$this->c->newsletter->work()['submitted']); self::assertCount(0,$this->mailer->messages);
    }

    public function testCampaignUnsubscribeDoesNotExpireOrRevokeEarlierLinksAndNeedsPost(): void
    {
        $admin=$this->admin(); $this->subscriber(); $id=$this->draft($admin); $this->c->newsletter->queue($admin,$id,1,true); $this->c->newsletter->work();
        $token=$this->deliveredToken();
        $id=$this->draft($admin); $this->c->newsletter->queue($admin,$id,1,true); $this->c->newsletter->work();
        self::assertSame(200,$this->request('GET','/newsletter/abmelden')->getStatusCode());
        self::assertSame('active',$this->c->db->one('SELECT state FROM newsletter_subscribers')['state']);
        $this->clock->advance(366*86400);
        $this->request('GET','/newsletter/abmelden');
        self::assertSame(200,$this->request('POST','/newsletter/abmelden',['_csrf'=>$this->csrf(),'token'=>$token])->getStatusCode());
        self::assertSame('unsubscribed',$this->c->db->one('SELECT state FROM newsletter_subscribers')['state']);
    }

    public function testAmbiguousSmtpDoesNotRetryAndConcurrentWorkerDoesNotDuplicate(): void
    {
        $admin=$this->admin(); $this->subscriber(); $id=$this->draft($admin); $this->c->newsletter->queue($admin,$id,1,true);
        $this->mailer->onSend=function(): void { self::assertSame(0,$this->c->newsletter->work()['submitted']); };
        $this->mailer->fail=true;
        self::assertSame(1,$this->c->newsletter->work()['ambiguous']);
        $this->mailer->fail=false; $this->mailer->onSend=null;
        self::assertSame(0,$this->c->newsletter->work()['submitted']); self::assertSame(1,$this->mailer->calls);
    }

    public function testDraftVersionAndExplicitConfirmationAreRequired(): void
    {
        $admin=$this->admin(); $this->subscriber(); $id=$this->draft($admin);
        $this->c->newsletter->save($admin,['subject'=>'Neuer Betreff','body'=>'Neuer Text','row_version'=>'1'],$id);
        try { $this->c->newsletter->queue($admin,$id,1,true); self::fail(); } catch (\DomainException) {}
        try { $this->c->newsletter->queue($admin,$id,2,false); self::fail(); } catch (\DomainException) {}
        self::assertSame(0,(int)$this->c->db->run('SELECT COUNT(*) FROM newsletter_deliveries')->fetchColumn());
        $this->c->newsletter->queue($admin,$id,2,true);
        $this->expectException(\DomainException::class); $this->c->newsletter->save($admin,['subject'=>'Unzulässig','body'=>'Text','row_version'=>'3'],$id);
    }

    public function testUnprivilegedAccountCannotReadOrQueueCampaigns(): void
    {
        $user=(int)$this->account()['id'];
        $this->expectException(\DomainException::class); $this->c->newsletter->overview($user);
    }

    public function testDisabledMailStillAllowsDraftsButNeverQueues(): void
    {
        $admin=$this->admin(); $this->subscriber();
        $disabled=new Container(new Config($this->root,$this->values(['APP_MAIL_ENABLED'=>false])),$this->clock,$this->mailer);
        $id=$disabled->newsletter->save($admin,['subject'=>'Entwurf','body'=>'Noch kein Versand'],null);
        $this->expectException(\DomainException::class); $disabled->newsletter->queue($admin,$id,1,true);
    }

    public function testProductionRateLimitPersistsAcrossWorkerInstances(): void
    {
        $admin=$this->admin(); $this->subscriber(); $this->subscriber('second@example.test');
        $production=new Container(new Config($this->root,$this->values(['APP_ENV'=>'production','APP_URL'=>'https://akademie.example.test','MAILER_DSN'=>'smtps://mail.example.test:465','MAIL_FROM'=>'system@corps-akademie.r4av.com','NEWSLETTER_MAIL_FROM'=>'newsletter@corps-akademie.r4av.com','MAIL_REPLY_TO'=>'info@corps-akademie.r4av.com'])),$this->clock,$this->mailer);
        $id=$production->newsletter->save($admin,['subject'=>'Produktionslimit','body'=>'Kleine Stapel'],null);
        $production->newsletter->queue($admin,$id,1,true);
        self::assertSame(1,$production->newsletter->work(10)['submitted']);
        self::assertSame(0,$production->newsletter->work(10)['submitted']);
        self::assertSame('newsletter@corps-akademie.r4av.com',$this->mailer->messages[0]->getFrom()[0]->getAddress());
        self::assertSame('info@corps-akademie.r4av.com',$this->mailer->messages[0]->getReplyTo()[0]->getAddress());
        $this->clock->advance(300);
        self::assertSame(1,$production->newsletter->work(10)['submitted']);
    }

    public function testAdminScreensRequireMfaAndCsrfAndRenderEscapedPreview(): void
    {
        self::assertSame('/anmelden',$this->request('GET','/admin/newsletter')->headers->get('Location'));
        $user=$this->account('owner@example.test','superadmin');
        $secret=$this->c->mfa->newSecret(); $otp=TOTP::createFromSecret($secret); $this->c->mfa->enroll((int)$user['id'],$secret,$otp->at($this->clock->timestamp)); $this->clock->advance(30);
        $this->login($user['email']);
        self::assertSame('/sicherheit/code',$this->request('GET','/admin/newsletter')->headers->get('Location'));
        $this->request('POST','/sicherheit/code',['_csrf'=>$this->csrf(),'code'=>$otp->at($this->clock->timestamp)]);
        self::assertSame(200,$this->request('GET','/admin/newsletter/neu')->getStatusCode());
        self::assertSame(403,$this->request('POST','/admin/newsletter/neu',['action'=>'save'])->getStatusCode());
        $response=$this->request('POST','/admin/newsletter/neu',['_csrf'=>$this->csrf(),'action'=>'save','subject'=>'<script>Betreff</script>','body'=>'<script>Nachricht</script>']);
        self::assertSame(303,$response->getStatusCode());
        $preview=$this->request('GET',$response->headers->get('Location'))->getContent();
        self::assertStringContainsString('&lt;script&gt;Nachricht&lt;/script&gt;',$preview);
        self::assertStringNotContainsString('<script>Nachricht</script>',$preview);
        self::assertSame(200,$this->request('GET','/admin/newsletter')->getStatusCode());
    }
}
