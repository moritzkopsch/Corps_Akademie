<?php
declare(strict_types=1);
namespace Corpsakademie\Tests;

use Corpsakademie\Tests\Support\Fixture;

final class CalendarTest extends Fixture
{
    /** Legt ein Konto mit verknüpftem Referentenprofil an. */
    private function referentUser(string $email='referent@example.test',string $status='published'): array
    {
        $user=$this->account($email,'instructor','Test Referent');
        $id=(int)$this->c->db->run('INSERT INTO referent_profiles(user_id,slug,name,headline,corps,biography,expertise_json,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?) RETURNING id',[
            $user['id'],'referent-'.bin2hex(random_bytes(4)),'Test Referent','Referentin','','Biografie','[]',$status,$this->clock->timestamp,$this->clock->timestamp,
        ])->fetchColumn();
        return ['user_id'=>(int)$user['id'],'id'=>$id,'email'=>$email];
    }

    private function admin(): int { return (int)$this->account('admin@example.test','admin')['id']; }

    private function windowCount(): int { return (int)$this->c->db->run('SELECT COUNT(*) FROM referent_availability')->fetchColumn(); }

    public function testAvailabilityIsStoredAndRemoved(): void
    {
        $referent=$this->referentUser();
        $this->c->calendar->saveWindow($referent['user_id'],['starts_on'=>'2026-11-02','ends_on'=>'2026-11-06','kind'=>'available','note'=>'Seminartage']);
        $windows=$this->c->calendar->windows($referent['id']);
        self::assertCount(1,$windows);
        self::assertSame('2026-11-02',$windows[0]['starts_on']);
        self::assertSame('available',$windows[0]['kind']);

        $this->c->calendar->removeWindow($referent['user_id'],(int)$windows[0]['id']);
        self::assertSame([],$this->c->calendar->windows($referent['id']));
        self::assertSame(0,$this->windowCount());
    }

    public function testReversedRangeIsRejected(): void
    {
        $referent=$this->referentUser();
        $this->expectException(\DomainException::class);
        $this->c->calendar->saveWindow($referent['user_id'],['starts_on'=>'2026-11-06','ends_on'=>'2026-11-02','kind'=>'available']);
    }

    public function testUnknownKindIsRejected(): void
    {
        $referent=$this->referentUser();
        $this->expectException(\DomainException::class);
        $this->c->calendar->saveWindow($referent['user_id'],['starts_on'=>'2026-11-02','ends_on'=>'2026-11-06','kind'=>'urlaub']);
    }

    public function testForeignWindowCannotBeRemoved(): void
    {
        $referent=$this->referentUser();
        $other=$this->referentUser('other@example.test');
        $this->c->calendar->saveWindow($referent['user_id'],['starts_on'=>'2026-11-02','ends_on'=>'2026-11-06','kind'=>'available']);
        $window=$this->c->calendar->windows($referent['id'])[0];
        $this->expectException(\DomainException::class);
        $this->c->calendar->removeWindow($other['user_id'],(int)$window['id']);
    }

    public function testProposalIsConfirmedByManagement(): void
    {
        $referent=$this->referentUser(); $admin=$this->admin();
        $this->c->calendar->propose($referent['user_id'],['title'=>'Seminar Kommunikation','day'=>'2026-11-10','location'=>'Bad Kösen','note'=>'']);
        $rows=$this->c->calendar->appointments($referent['id']);
        self::assertCount(1,$rows);
        self::assertSame('proposed',$rows[0]['state']);
        self::assertSame('referent',$rows[0]['source']);

        $board=$this->c->calendar->board($admin);
        self::assertCount(1,$board['pending']);

        $this->c->calendar->decide($admin,(int)$rows[0]['id'],'confirmed');
        $decided=$this->c->calendar->appointments($referent['id']);
        self::assertSame('confirmed',$decided[0]['state']);
    }

    public function testWithdrawOnlyAppliesToOwnProposals(): void
    {
        $referent=$this->referentUser(); $admin=$this->admin();
        $this->c->calendar->plan($admin,$referent['id'],['title'=>'Fester Termin','day'=>'2026-11-12']);
        $rows=$this->c->calendar->appointments($referent['id']);
        self::assertSame('confirmed',$rows[0]['state']);
        self::assertSame('admin',$rows[0]['source']);
        $this->expectException(\DomainException::class);
        $this->c->calendar->withdraw($referent['user_id'],(int)$rows[0]['id']);
    }

    public function testReferentWithdrawsOwnProposal(): void
    {
        $referent=$this->referentUser();
        $this->c->calendar->propose($referent['user_id'],['title'=>'Vorschlag','day'=>'2026-11-14']);
        $appointment=$this->c->calendar->appointments($referent['id'])[0];
        $this->c->calendar->withdraw($referent['user_id'],(int)$appointment['id']);
        self::assertSame('cancelled',$this->c->calendar->appointments($referent['id'])[0]['state']);
    }

    public function testCoverageMarksMonthsWithWindowOrAppointment(): void
    {
        $referent=$this->referentUser();
        self::assertSame([false,false,false,false,false,false],array_column($this->c->calendar->coverage($referent['id']),'planned'));

        $this->c->calendar->saveWindow($referent['user_id'],['starts_on'=>'2026-11-01','ends_on'=>'2026-11-30','kind'=>'available']);
        $planned=array_column($this->c->calendar->coverage($referent['id']),'planned');
        self::assertTrue($planned[1]);
        self::assertFalse($planned[0]);

        $this->c->calendar->propose($referent['user_id'],['title'=>'Seminartag','day'=>'2026-12-08']);
        $planned=array_column($this->c->calendar->coverage($referent['id']),'planned');
        self::assertTrue($planned[2]);
        self::assertFalse($planned[0]);
    }

    public function testReminderIsSentOncePerMonthAndSkipsPlannedReferents(): void
    {
        $referent=$this->referentUser();
        $planned=$this->referentUser('planned@example.test');
        $this->c->calendar->saveWindow($planned['user_id'],['starts_on'=>'2026-10-01','ends_on'=>'2027-03-31','kind'=>'available']);

        $first=$this->c->calendar->remind(5);
        self::assertSame(1,$first['submitted']);
        self::assertCount(1,$this->mailer->messages);
        self::assertSame('referent@example.test',$this->mailer->messages[0]->getTo()[0]->getAddress());

        $second=$this->c->calendar->remind(5);
        self::assertSame(0,$second['submitted']);
        self::assertCount(1,$this->mailer->messages);
    }

    public function testReminderRequiresEnabledMail(): void
    {
        $config=new \Corpsakademie\Infrastructure\Config($this->root,$this->values(['APP_MAIL_ENABLED'=>'false']));
        $calendar=new \Corpsakademie\Application\Calendar($this->c->db,$config,$this->c->accounts,$this->c->audit,$this->clock,$this->mailer);
        $this->expectException(\DomainException::class);
        $calendar->remind(5);
    }

    public function testPortalCalendarAcceptsAvailabilityForLinkedReferent(): void
    {
        $this->referentUser();
        $this->login();
        self::assertSame(200,$this->request('GET','/portal/kalender')->getStatusCode());
        $response=$this->request('POST','/portal/kalender',[
            'action'=>'window','starts_on'=>'2026-12-01','ends_on'=>'2026-12-05','kind'=>'blocked','note'=>'','_csrf'=>$this->csrf(),
        ]);
        self::assertSame(200,$response->getStatusCode());
        self::assertSame(1,$this->windowCount());
    }

    /** Meldet ein Konto mit MFA-Pflicht vollständig an. */
    private function loginWithMfa(string $email,string $role): void
    {
        $user=$this->account($email,$role,'Test Administration');
        $secret=$this->c->mfa->newSecret();
        $otp=\OTPHP\TOTP::createFromSecret($secret);
        $this->c->mfa->enroll((int)$user['id'],$secret,$otp->at($this->clock->timestamp));
        $this->clock->advance(30);
        $this->login($email);
        $this->request('POST','/sicherheit/code',['_csrf'=>$this->csrf(),'code'=>$otp->at($this->clock->timestamp)]);
    }

    public function testAdminCalendarListsReferentCoverage(): void
    {
        $referent=$this->referentUser();
        $this->loginWithMfa('admin@example.test','admin');
        $page=$this->request('GET','/admin/kalender');
        self::assertSame(200,$page->getStatusCode());
        self::assertStringContainsString('Test Referent',$page->getContent());
        $this->request('POST','/admin/kalender',[
            'action'=>'plan','referent_id'=>(string)$referent['id'],'title'=>'Bestätigter Termin','day'=>'2026-11-20','_csrf'=>$this->csrf(),
        ]);
        self::assertSame(1,(int)$this->c->db->run("SELECT COUNT(*) FROM referent_appointments WHERE state='confirmed' AND source='admin'")->fetchColumn());
    }
}
