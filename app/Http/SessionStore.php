<?php
declare(strict_types=1);
namespace Corpsakademie\Http;

use Corpsakademie\Infrastructure\{Config,Crypto,Database,IdentityRepository};
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\{Cookie,Request,Response};

final class SessionStore
{
    public const COOKIE = 'corpsakademie_session';
    public function __construct(private readonly Database $db, private readonly Config $config, private readonly Crypto $crypto, private readonly ClockInterface $clock, private readonly IdentityRepository $store) {}

    public function load(Request $request): Session
    {
        $token = $request->cookies->get(self::COOKIE, '');
        $now = $this->clock->now()->getTimestamp();
        if (is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            $row = $this->store->activeSession(Crypto::hash($token),$now);
            if ($row !== null) {
                $data = json_decode($this->crypto->decrypt($row['payload_ciphertext'], 'session', $row['key_id']), true, 512, JSON_THROW_ON_ERROR);
                if (!is_string($data['csrf'] ?? null)) { throw new \RuntimeException('Ungültige Sitzung.'); }
                if ((int) $row['last_seen_at'] <= $now - min(60, max(1, intdiv($this->config->idleSeconds, 4)))) {
                    $this->store->touchSession((int)$row['id'],$now,$now+$this->config->idleSeconds);
                }
                return new Session($token, $row['user_id'] === null ? null : (int) $row['user_id'], $data, (int) $row['absolute_expires_at']);
            }
        }
        return $this->create(null, ['csrf' => Crypto::token(), 'mfa_verified' => false]);
    }

    public function rotate(Session $old, ?int $userId, array $data = []): Session
    {
        return $this->db->immediate(function () use ($old,$userId,$data): Session {
            if ($userId!==null) {
                $now=$this->clock->now()->getTimestamp();
                $active=$this->store->activeSession(Crypto::hash($old->token),$now);
                if ($active===null || ($old->userId!==null && $old->userId!==$userId)) { throw new \DomainException('Die Anmeldung ist abgelaufen. Bitte erneut anmelden.'); }
            }
            $this->store->revokeSession(Crypto::hash($old->token),$this->clock->now()->getTimestamp());
            return $this->create($userId, array_replace(['csrf' => Crypto::token(), 'mfa_verified' => false], $data));
        });
    }

    private function create(?int $userId, array $data): Session
    {
        $now = $this->clock->now()->getTimestamp();
        $session = new Session(Crypto::token(), $userId, $data, $now+$this->config->absoluteSeconds);
        $session->new = true;
        $this->store->insertSession($userId,Crypto::hash($session->token),$this->crypto->encrypt(json_encode($data,JSON_THROW_ON_ERROR),'session'),$this->config->keyId,$now,$now+$this->config->idleSeconds,$session->absoluteExpiry);
        return $session;
    }

    public function save(Session $session, Response $response): void
    {
        if ($session->dirty) {
            $this->store->saveSession(Crypto::hash($session->token),$this->crypto->encrypt(json_encode($session->data,JSON_THROW_ON_ERROR),'session'));
        }
        if ($session->new) {
            $response->headers->setCookie(Cookie::create(self::COOKIE)->withValue($session->token)->withExpires($session->absoluteExpiry)
                ->withPath('/')->withSecure($this->config->secureCookies())->withHttpOnly(true)->withSameSite(Cookie::SAMESITE_LAX));
        }
    }
}
