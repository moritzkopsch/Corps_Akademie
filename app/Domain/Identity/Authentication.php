<?php
declare(strict_types=1);
namespace Corpsakademie\Domain\Identity;

use Corpsakademie\Http\{Session,SessionStore};
use Corpsakademie\Infrastructure\{Audit,Crypto,Database,IdentityRepository};

final class Authentication
{
    // A dummy verification hash, never a usable account credential.
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=4,p=1$ODlEdkxRRG1MTlJWaUZoTA$OyLGd8Htv5LluZt0IbT1XPeSEbQ3qVxe2g8cFOUIlks';
    public function __construct(private readonly Accounts $accounts, private readonly RateLimiter $limits, private readonly Audit $audit, private readonly Crypto $crypto, private readonly Database $db, private readonly SessionStore $sessions, private readonly IdentityRepository $store) {}

    public function login(string $email, string $password, string $ip, Session $session): Session
    {
        $ipAllowed = $this->limits->attempt('login.ip',$ip,30,900);
        $emailAllowed = $this->limits->attempt('login.email',Accounts::emailKey($email),8,900);
        if (!$ipAllowed || !$emailAllowed) {
            throw new \DomainException('Zu viele Anmeldeversuche. Bitte in 15 Minuten erneut versuchen.');
        }
        $user = strlen($email) <= 254 ? $this->accounts->findByEmail($email) : null;
        $passwordMatches = strlen($password) <= 1024 && password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);
        if (!$passwordMatches || $user === null || $user['status'] !== 'active') {
            $this->audit->security('login.failed',$this->crypto->subject('ip',$ip));
            throw new \DomainException('E-Mail-Adresse oder Passwort ist nicht korrekt.');
        }
        $rehash = password_needs_rehash($user['password_hash'],PASSWORD_ARGON2ID) ? password_hash($password,PASSWORD_ARGON2ID) : null;
        return $this->db->immediate(function () use ($session,$user,$rehash): Session {
            $current=$this->accounts->find((int)$user['id']);
            if ($current===null || $current['status']!=='active' || !hash_equals($current['password_hash'],$user['password_hash'])) {
                throw new \DomainException('E-Mail-Adresse oder Passwort ist nicht korrekt.');
            }
            if ($rehash!==null) { $this->store->replacePasswordHash((int)$user['id'],$rehash); }
            $this->audit->record('login.password_verified',(int)$user['id'],$user['public_id']);
            return $this->sessions->rotate($session,(int)$user['id']);
        });
    }
}
