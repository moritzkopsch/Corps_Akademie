<?php
declare(strict_types=1);
namespace Corpsakademie\Application;

use Corpsakademie\Domain\Identity\{Accounts,RateLimiter,Tokens};
use Corpsakademie\Infrastructure\{Audit,Config,Crypto,Database,IdentityRepository,MailOutbox};
use Psr\Clock\ClockInterface;

final class AccountLinks
{
    /** Einladungen bleiben deutlich länger gültig als Wiederherstellungslinks. */
    private const INVITATION_SECONDS = 14 * 86400;

    public function __construct(private readonly Database $db, private readonly Accounts $accounts, private readonly Tokens $tokens, private readonly MailOutbox $mail, private readonly RateLimiter $limits, private readonly Audit $audit, private readonly Config $config, private readonly ClockInterface $clock, private readonly IdentityRepository $store) {}

    public function isUnconfigured(): bool
    {
        return $this->store->userCount() === 0;
    }

    public function setupLink(): string
    {
        return $this->db->immediate(function (): string {
            if (!$this->isUnconfigured()) { throw new \DomainException('Die Ersteinrichtung ist bereits abgeschlossen.'); }
            $issued = $this->tokens->issue('bootstrap',null);
            return $this->config->url.'/einrichten#token='.$issued['token'];
        });
    }

    public function setup(string $token, string $email, string $name, string $password): array
    {
        return $this->db->immediate(function () use ($token,$email,$name,$password): array {
            if (!$this->isUnconfigured()) { throw new \DomainException('Die Ersteinrichtung ist bereits abgeschlossen.'); }
            $this->tokens->consume('bootstrap',$token);
            $user = $this->accounts->create($email,$name,$password,'superadmin');
            $this->queueVerification($user);
            $this->audit->record('setup.completed',(int)$user['id'],$user['public_id']);
            return $user;
        });
    }

    /** Legt ein Konto an und verschickt einen Einrichtungslink; das Passwort setzt die eingeladene Person selbst. */
    public function invite(int $actorId, string $email, string $name, string $role): array
    {
        return $this->db->immediate(function () use ($actorId,$email,$name,$role): array {
            // Das Zufallspasswort ist nur ein Platzhalter; gültig wird das Konto über den Einrichtungslink.
            $user = $this->accounts->create($email,$name,Crypto::token(),$role);
            // Einladungen nutzen den Wiederherstellungspfad, der beim Setzen zugleich die E-Mail-Adresse bestätigt.
            $token = $this->tokens->issue('password_reset',(int)$user['id'],self::INVITATION_SECONDS);
            $this->mail->enqueue('account_invitation',$user,$token);
            $this->audit->record('account.invited',$actorId,$user['public_id'],['role'=>$role]);
            return $user;
        });
    }

    public function queueVerification(array $user): void
    {
        $this->db->immediate(function () use ($user): void {
            $token = $this->tokens->issue('account_verification',(int)$user['id']);
            $this->mail->enqueue('account_verification',$user,$token);
        });
    }

    public function resendVerification(int $userId, string $ip): void
    {
        $allowed = $this->limits->attempt('verify.ip',$ip,10,900);
        $userAllowed = $this->limits->attempt('verify.user',(string)$userId,3,900);
        $user = $this->accounts->find($userId);
        if ($allowed && $userAllowed && $user !== null && $user['status']==='active' && $user['email_verified_at'] === null) {
            $this->queueVerification($user);
        }
    }

    public function confirmEmail(string $token): array
    {
        return $this->db->immediate(function () use ($token): array {
            $row = $this->tokens->consume('account_verification',$token);
            $user = $this->accounts->find((int)$row['user_id']);
            if ($user === null || $user['status'] !== 'active') { throw new \DomainException('Der Link ist ungültig oder abgelaufen.'); }
            $now = $this->clock->now()->getTimestamp();
            $this->store->verifyEmail((int)$user['id'],$now);
            $this->audit->record('email.confirmed',(int)$user['id'],$user['public_id']);
            return $this->accounts->find((int)$user['id']);
        });
    }

    public function requestReset(string $email, string $ip): void
    {
        $allowed = $this->limits->attempt('reset.ip',$ip,10,900);
        $emailAllowed = $this->limits->attempt('reset.email',Accounts::emailKey($email),3,900);
        if (!$allowed || !$emailAllowed || strlen($email)>254) { return; }
        $user = $this->accounts->findByEmail($email);
        if ($user === null || $user['status'] !== 'active') { return; }
        $this->db->immediate(function () use ($user): void {
            $token = $this->tokens->issue('password_reset',(int)$user['id']);
            $this->mail->enqueue('password_reset',$user,$token);
            $this->audit->record('password.reset_requested',null,$user['public_id']);
        });
    }

    public function reset(string $token, string $password): void
    {
        Accounts::checkPassword($password);
        $hash = password_hash($password,PASSWORD_ARGON2ID);
        $this->db->immediate(function () use ($token,$hash): void {
            $row = $this->tokens->consume('password_reset',$token);
            $user = $this->accounts->find((int)$row['user_id']);
            if ($user === null || $user['status'] !== 'active') { throw new \DomainException('Der Link ist ungültig oder abgelaufen.'); }
            $now = $this->clock->now()->getTimestamp();
            $this->store->resetPassword((int)$user['id'],$hash,$now);
            $this->store->revokeSessions((int)$user['id'],$now);
            $this->store->revokeTokens((int)$user['id'],$now);
            $this->store->cancelQueuedMail((int)$user['id']);
            $this->audit->record('password.reset_completed',(int)$user['id'],$user['public_id']);
        });
    }
}
