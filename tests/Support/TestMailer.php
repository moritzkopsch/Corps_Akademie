<?php
declare(strict_types=1);
namespace Corpsakademie\Tests\Support;

use Symfony\Component\Mailer\{Envelope,MailerInterface};
use Symfony\Component\Mime\RawMessage;

final class TestMailer implements MailerInterface
{
    public array $messages=[];
    public bool $fail=false;
    public int $calls=0;
    public ?\Closure $onSend=null;
    public function send(RawMessage $message,?Envelope $envelope=null): void
    {
        $this->calls++;
        if ($this->onSend!==null) { ($this->onSend)(); }
        if ($this->fail) { throw new \RuntimeException('Synthetic transport failure'); }
        $this->messages[]=$message;
    }
}
