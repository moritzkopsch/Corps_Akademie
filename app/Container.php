<?php
declare(strict_types=1);
namespace Corpsakademie;

use Corpsakademie\Application\{AccountLinks,Catalog,PublicRequests};
use Corpsakademie\Domain\Identity\{Accounts,Authentication,Mfa,RateLimiter,Tokens};
use Corpsakademie\Http\SessionStore;
use Corpsakademie\Infrastructure\{Audit,CatalogRepository,Config,Crypto,Database,IdentityRepository,InquiryRepository,MailOutbox,PublicMailOutbox,SystemClock};
use Psr\Clock\ClockInterface;
use Symfony\Component\Mailer\{Mailer,MailerInterface,Transport};
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

final class Container
{
    public readonly ClockInterface $clock;
    public readonly Database $db;
    public readonly IdentityRepository $identity;
    public readonly Crypto $crypto;
    public readonly Audit $audit;
    public readonly Accounts $accounts;
    public readonly RateLimiter $limits;
    public readonly SessionStore $sessions;
    public readonly Tokens $tokens;
    public readonly Mfa $mfa;
    public readonly Authentication $auth;
    public readonly MailOutbox $mail;
    public readonly AccountLinks $links;
    public readonly CatalogRepository $catalog;
    public readonly Catalog $content;
    public readonly PublicMailOutbox $publicMail;
    public readonly PublicRequests $publicRequests;

    public function __construct(public readonly Config $config, ?ClockInterface $clock = null, ?MailerInterface $mailer = null)
    {
        $this->clock = $clock ?? new SystemClock();
        $this->db = new Database($config);
        $this->identity = new IdentityRepository($this->db);
        $this->crypto = new Crypto($config);
        $this->audit = new Audit($this->db,$this->clock);
        $this->accounts = new Accounts($this->db,$this->audit,$this->clock,$this->identity);
        $this->limits = new RateLimiter($this->db,$this->crypto,$this->clock,$this->identity);
        $this->sessions = new SessionStore($this->db,$config,$this->crypto,$this->clock,$this->identity);
        $this->tokens = new Tokens($this->db,$this->clock,$this->identity);
        $this->mfa = new Mfa($this->db,$this->crypto,$config,$this->clock,$this->audit,$this->identity);
        $this->auth = new Authentication($this->accounts,$this->limits,$this->audit,$this->crypto,$this->db,$this->sessions,$this->identity);
        if ($mailer === null) {
            $transport = Transport::fromDsn($config->mailerDsn);
            if ($transport instanceof SmtpTransport) { $transport->getStream()->setTimeout(10); }
            $mailer = new Mailer($transport);
        }
        $this->mail = new MailOutbox($this->db,$config,$this->crypto,$this->clock,$mailer);
        $this->links = new AccountLinks($this->db,$this->accounts,$this->tokens,$this->mail,$this->limits,$this->audit,$config,$this->clock,$this->identity);
        $this->catalog = new CatalogRepository($this->db);
        $this->content = new Catalog($this->db,$this->catalog,$this->accounts,$this->audit,$this->clock);
        $this->publicMail = new PublicMailOutbox($this->db,$config,$this->crypto,$this->clock,$mailer);
        $this->publicRequests = new PublicRequests($this->db,$this->catalog,new InquiryRepository($this->db),$this->publicMail,$this->crypto,$config,$this->limits,$this->accounts,$this->audit,$this->clock);
    }
}
