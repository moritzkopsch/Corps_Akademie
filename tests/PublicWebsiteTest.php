<?php
declare(strict_types=1);
namespace Corpsakademie\Tests;

use Corpsakademie\Infrastructure\{CatalogSeeder,Crypto};
use Corpsakademie\Tests\Support\Fixture;
use OTPHP\TOTP;

final class PublicWebsiteTest extends Fixture
{
    protected function setUp(): void
    {
        parent::setUp();
        CatalogSeeder::seed($this->c->db,$this->c->catalog,$this->root,$this->clock->timestamp);
    }

    private function inquiry(array $changes=[]): array
    {
        return array_replace(['submission'=>Crypto::token(),'seminar'=>'bewerbungstraining','name'=>'Test Ansprechpartner','email'=>'anfrage@example.test','corps'=>'Testcorps','role'=>'Altherrenvorsitzender','phone'=>'','message'=>'Ein gemeinsames Seminar für unsere Aktivitas.','participants'=>'12','date'=>'','location'=>'Testcorpshaus','format'=>'Corpshaus / Präsenz','referent_mode'=>'recommend','consent'=>'1','website'=>''],$changes);
    }

    private function administrator(): array
    {
        $user=$this->account('admin@example.test','superadmin','Test Administration');
        $secret=$this->c->mfa->newSecret();
        $otp=TOTP::createFromSecret($secret); $code=$otp->at($this->clock->timestamp);
        $this->c->mfa->enroll((int)$user['id'],$secret,$code);
        $this->clock->advance(30);
        $this->login($user['email']);
        self::assertSame(303,$this->request('POST','/sicherheit/code',['_csrf'=>$this->csrf(),'code'=>$otp->at($this->clock->timestamp)])->getStatusCode());
        return $user;
    }

    public function testAllPublicPagesAndProfilesAreAccessibleWithoutLogin(): void
    {
        $paths=['/','/ueber-uns','/seminare','/referenten','/termine','/faq','/kontakt','/anfrage','/newsletter','/datenschutz','/impressum','/anfrage?seminar=bewerbungstraining','/anfrage/bestaetigen','/newsletter/bestaetigen','/newsletter/abmelden'];
        foreach ($this->c->catalog->seminars() as $seminar) { $paths[]='/seminare/'.$seminar['slug']; }
        foreach ($this->c->catalog->referents() as $referent) { $paths[]='/referenten/'.$referent['slug']; }
        foreach ($paths as $path) {
            $response=$this->request('GET',$path);
            self::assertSame(200,$response->getStatusCode(),$path);
            self::assertStringContainsString('Hauptnavigation',$response->getContent());
            self::assertStringContainsString("frame-ancestors 'none'",$response->headers->get('Content-Security-Policy'));
            self::assertSame('same-origin',$response->headers->get('Referrer-Policy'));
        }
        self::assertSame(404,$this->request('GET','/seminare/unbekannt')->getStatusCode());
        self::assertSame(404,$this->request('GET','/referenten/unbekannt')->getStatusCode());
        self::assertSame('/anmelden',$this->request('GET','/admin/anfragen')->headers->get('Location'));
    }

    public function testCatalogFiltersAndSearchEscapeSqlAndHtml(): void
    {
        $response=$this->request('GET','/seminare?thema=studium');
        self::assertStringContainsString('Zeitmanagement',$response->getContent());
        self::assertStringNotContainsString('Bewerbungstraining',$response->getContent());
        $response=$this->request('GET','/seminare?suche='.urlencode('<script>alert(1)</script>'));
        self::assertStringNotContainsString('<script>alert(1)</script>',$response->getContent());
        self::assertStringContainsString('&lt;script&gt;',$response->getContent());
        self::assertCount(0,$this->c->catalog->seminars(true,'',"%' OR 1=1 --"));
        self::assertCount(0,$this->c->catalog->seminars(true,'','%'));
    }

    public function testSeedIsIdempotentAndDoesNotOverwriteEdits(): void
    {
        $this->c->db->run("UPDATE seminars SET title='Redaktionell geändert' WHERE slug='rhetorik'");
        self::assertSame(['seminars'=>0,'referents'=>0],CatalogSeeder::seed($this->c->db,$this->c->catalog,$this->root,$this->clock->timestamp));
        self::assertSame('Redaktionell geändert',$this->c->catalog->seminar('rhetorik')['title']);
        self::assertSame(0,(int)$this->c->db->run('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testGuestInquiryRequiresVerificationAndIsIdempotent(): void
    {
        $admin=$this->account('admin@example.test','admin');
        $this->request('GET','/anfrage?seminar=bewerbungstraining');
        $data=$this->inquiry(['_csrf'=>$this->csrf()]);
        self::assertSame(200,$this->request('POST','/anfrage',$data)->getStatusCode());
        self::assertSame(200,$this->request('POST','/anfrage',$data)->getStatusCode());
        $row=$this->c->db->one('SELECT * FROM public_inquiries');
        self::assertSame('unverified',$row['state']);
        self::assertStringNotContainsString('Test Ansprechpartner',$row['details_ciphertext']);
        self::assertCount(0,$this->c->publicRequests->listForAdmin((int)$admin['id']));
        self::assertSame(1,(int)$this->c->db->run('SELECT COUNT(*) FROM public_inquiries')->fetchColumn());
        self::assertSame(1,$this->c->publicMail->work()['submitted']);
        $token=$this->deliveredToken();
        self::assertSame(200,$this->request('GET','/anfrage/bestaetigen')->getStatusCode());
        self::assertSame('unverified',$this->c->db->one('SELECT state FROM public_inquiries')['state']);
        self::assertSame(200,$this->request('POST','/anfrage/bestaetigen',['_csrf'=>$this->csrf(),'token'=>$token])->getStatusCode());
        $list=$this->c->publicRequests->listForAdmin((int)$admin['id']);
        self::assertCount(1,$list); self::assertSame('received',$list[0]['state']);
        self::assertSame('Test Ansprechpartner',$list[0]['details']['name']);
        self::assertSame(422,$this->request('POST','/anfrage/bestaetigen',['_csrf'=>$this->csrf(),'token'=>$token])->getStatusCode());
        self::assertSame(1,$this->c->publicMail->work()['submitted']);
        self::assertStringContainsString('keine verbindliche Buchungsbestätigung',$this->mailer->messages[1]->getTextBody());
    }

    public function testInquiryRejectsCsrfOriginInvalidDatesAndReferents(): void
    {
        $this->request('GET','/anfrage');
        self::assertSame(403,$this->request('POST','/anfrage',$this->inquiry())->getStatusCode());
        self::assertSame(403,$this->request('POST','/anfrage',$this->inquiry(['_csrf'=>$this->csrf()]),['HTTP_ORIGIN'=>'https://untrusted.example'])->getStatusCode());
        self::assertSame(403,$this->request('POST','/anfrage',$this->inquiry(['_csrf'=>$this->csrf()]),['HTTP_ORIGIN'=>'null'])->getStatusCode());
        foreach ([['date'=>'2026-02-30'],['referent_mode'=>'team'],['referent_mode'=>'one_of','referents'=>['9999']],['participants'=>'-2'],['consent'=>''],['email'=>'real@example.org'],['seminar'=>'unbekannt']] as $changes) {
            self::assertSame(422,$this->request('POST','/anfrage',$this->inquiry($changes+['_csrf'=>$this->csrf()]))->getStatusCode());
        }
        self::assertSame(0,(int)$this->c->db->run('SELECT COUNT(*) FROM public_inquiries')->fetchColumn());
    }

    public function testReferentModesAreSavedAsPreferencesWithoutBooking(): void
    {
        $ids=array_map(static fn(array $r): string=>(string)$r['id'],$this->c->catalog->referents(true,(int)$this->c->catalog->seminar('bewerbungstraining')['id']));
        foreach (['one_of','team'] as $mode) { $this->c->publicRequests->submit($this->inquiry(['referent_mode'=>$mode,'referents'=>$ids]),'seminar','127.0.0.1'); }
        self::assertSame(2,(int)$this->c->db->run("SELECT COUNT(*) FROM public_inquiries WHERE state='unverified'")->fetchColumn());
        $row=$this->c->db->one('SELECT * FROM public_inquiries ORDER BY id DESC');
        $data=json_decode($this->c->crypto->decrypt($row['details_ciphertext'],'inquiry',$row['key_id']),true);
        self::assertSame('team',$data['referent_mode']); self::assertCount(2,$data['referents']);
    }

    public function testExpiredAndWrongPurposeTokensCannotActivateInquiry(): void
    {
        $this->c->publicRequests->submit($this->inquiry(),'seminar','127.0.0.1'); $this->c->publicMail->work(); $token=$this->deliveredToken();
        try { $this->c->publicRequests->confirmNewsletter($token,'127.0.0.1'); self::fail('wrong purpose accepted'); } catch (\DomainException) {}
        $this->clock->advance(1800);
        $this->expectException(\DomainException::class);
        $this->c->publicRequests->confirmInquiry($token,'127.0.0.1');
    }

    public function testNewsletterDoubleOptInAndUnsubscribe(): void
    {
        $this->request('GET','/newsletter');
        $data=['email'=>'newsletter@example.test','consent'=>'1','_csrf'=>$this->csrf()];
        self::assertSame(200,$this->request('POST','/newsletter',$data)->getStatusCode());
        self::assertSame('pending',$this->c->db->one('SELECT state FROM newsletter_subscribers')['state']);
        $this->c->publicMail->work(); $token=$this->deliveredToken();
        self::assertSame(200,$this->request('POST','/newsletter/bestaetigen',['_csrf'=>$this->csrf(),'token'=>$token])->getStatusCode());
        self::assertSame('active',$this->c->db->one('SELECT state FROM newsletter_subscribers')['state']);
        self::assertSame(422,$this->request('POST','/newsletter/bestaetigen',['_csrf'=>$this->csrf(),'token'=>$token])->getStatusCode());
        $this->c->publicMail->work(); $unsubscribe=$this->deliveredToken(1);
        self::assertSame(200,$this->request('GET','/newsletter/abmelden')->getStatusCode());
        self::assertSame('active',$this->c->db->one('SELECT state FROM newsletter_subscribers')['state']);
        self::assertSame(200,$this->request('POST','/newsletter/abmelden',['_csrf'=>$this->csrf(),'token'=>$unsubscribe])->getStatusCode());
        self::assertSame('unsubscribed',$this->c->db->one('SELECT state FROM newsletter_subscribers')['state']);
        self::assertSame(['newsletter.requested','newsletter.confirmed','newsletter.unsubscribed'],$this->c->db->run("SELECT action FROM audit_logs WHERE entity_type='subscriber' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        self::assertSame(422,$this->request('POST','/newsletter/abmelden',['_csrf'=>$this->csrf(),'token'=>$unsubscribe])->getStatusCode());
    }

    public function testNewsletterReissueCancelsOldMailAndActiveSubscriptionIsPreserved(): void
    {
        $data=['email'=>'newsletter@example.test','consent'=>'1'];
        $this->c->publicRequests->subscribe($data,'127.0.0.1'); $this->c->publicRequests->subscribe($data,'127.0.0.1');
        $result=$this->c->publicMail->work(); self::assertSame(1,$result['cancelled']); self::assertSame(1,$result['submitted']);
        $this->c->publicRequests->confirmNewsletter($this->deliveredToken(),'127.0.0.1');
        $this->c->publicRequests->subscribe($data,'127.0.0.1');
        self::assertSame('active',$this->c->db->one('SELECT state FROM newsletter_subscribers')['state']);
        self::assertSame(3,(int)$this->c->db->run('SELECT COUNT(*) FROM public_mail_outbox')->fetchColumn());
    }

    public function testPublicMailAmbiguityIsNotRetriedAndSecretIsErased(): void
    {
        $this->c->publicRequests->submit($this->inquiry(),'seminar','127.0.0.1');
        $this->mailer->fail=true;
        self::assertSame(1,$this->c->publicMail->work()['ambiguous']);
        self::assertNull($this->c->db->one('SELECT payload_ciphertext FROM public_mail_outbox')['payload_ciphertext']);
        self::assertSame(0,array_sum($this->c->publicMail->work()));
    }

    public function testInstructorCannotReadRequestsOrEditCatalog(): void
    {
        $instructor=$this->account(); $this->login();
        foreach (['/admin/seminare','/admin/referenten','/admin/anfragen'] as $path) { self::assertSame(403,$this->request('GET',$path)->getStatusCode()); }
        $this->expectException(\DomainException::class);
        $this->c->publicRequests->listForAdmin((int)$instructor['id']);
    }

    public function testAdminCanCreatePublishEditAndArchiveSeminarWithStaleWriteProtection(): void
    {
        $this->administrator();
        foreach (['/admin/seminare','/admin/referenten','/admin/anfragen','/admin/seminare/neu','/admin/referenten/neu','/admin/seminare/bewerbungstraining','/admin/referenten/jan-wattjes'] as $path) { self::assertSame(200,$this->request('GET',$path)->getStatusCode(),$path); }
        $values=['_csrf'=>$this->csrf(),'slug'=>'neues-seminar','title'=>'<script>Test</script>','category_id'=>'1','summary'=>'Testinhalt','description'=>'Ein fachlicher Testinhalt.','benefits'=>"Lernziel eins\nLernziel zwei",'audience'=>'Testgruppe','duration'=>'Nach Abstimmung','format'=>'Präsenz','status'=>'draft','row_version'=>'0'];
        self::assertSame(303,$this->request('POST','/admin/seminare/neu',$values)->getStatusCode());
        self::assertSame(404,$this->request('GET','/seminare/neues-seminar')->getStatusCode());
        $values['status']='published'; $values['row_version']='1';
        self::assertSame(303,$this->request('POST','/admin/seminare/neues-seminar',$values)->getStatusCode());
        $page=$this->request('GET','/seminare/neues-seminar');
        self::assertSame(200,$page->getStatusCode()); self::assertStringNotContainsString('<script>Test</script>',$page->getContent());
        self::assertStringContainsString('&lt;script&gt;Test&lt;/script&gt;',$page->getContent());
        self::assertSame(422,$this->request('POST','/admin/seminare/neues-seminar',$values)->getStatusCode());
        foreach (['inactive','archived'] as $status) {
            $values['status']=$status; $values['row_version']=(string)$this->c->catalog->seminar('neues-seminar',false)['row_version'];
            self::assertSame(303,$this->request('POST','/admin/seminare/neues-seminar',$values)->getStatusCode());
            self::assertSame(404,$this->request('GET','/seminare/neues-seminar')->getStatusCode());
        }
    }

    public function testAdminCanManageProfilesAndProcessVerifiedContactRequest(): void
    {
        $admin=$this->administrator();
        $values=['_csrf'=>$this->csrf(),'slug'=>'test-referent','name'=>'Test Referent','headline'=>'Ein Testprofil','corps'=>'Testcorps','biography'=>'Ein redaktioneller Testtext.','expertise'=>"Thema eins\nThema zwei",'status'=>'published','row_version'=>'0'];
        self::assertSame(303,$this->request('POST','/admin/referenten/neu',$values)->getStatusCode());
        self::assertSame(200,$this->request('GET','/referenten/test-referent')->getStatusCode());
        $this->c->publicRequests->submit($this->inquiry(['message'=>'Eine Kontaktanfrage.']),'contact','127.0.0.1');
        $this->c->publicMail->work(); $this->c->publicRequests->confirmInquiry($this->deliveredToken(),'127.0.0.1');
        $inquiry=$this->c->publicRequests->listForAdmin((int)$admin['id'])[0];
        self::assertSame(200,$this->request('GET','/admin/anfragen/'.$inquiry['public_id'])->getStatusCode());
        self::assertSame(303,$this->request('POST','/admin/anfragen/'.$inquiry['public_id'],['_csrf'=>$this->csrf(),'state'=>'in_progress'])->getStatusCode());
        self::assertSame('in_progress',$this->c->publicRequests->detailForAdmin((int)$admin['id'],$inquiry['public_id'])['state']);
    }
}
