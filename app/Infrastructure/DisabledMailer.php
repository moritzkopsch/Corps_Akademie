<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

use Symfony\Component\Mailer\{Envelope,MailerInterface};
use Symfony\Component\Mime\RawMessage;

final class DisabledMailer implements MailerInterface
{
    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        throw new \DomainException('Der E-Mail-Versand ist noch nicht eingerichtet.');
    }
}
