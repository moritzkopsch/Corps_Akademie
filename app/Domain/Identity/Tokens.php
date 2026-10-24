<?php
declare(strict_types=1);
namespace Corpsakademie\Domain\Identity;

use Corpsakademie\Infrastructure\{Crypto,Database,IdentityRepository};
use Psr\Clock\ClockInterface;

final class Tokens
{
    public function __construct(private readonly Database $db, private readonly ClockInterface $clock, private readonly IdentityRepository $store) {}

    public function issue(string $purpose, ?int $userId, int $seconds = 1800): array
    {
        return $this->db->immediate(function () use ($purpose,$userId,$seconds): array {
            $now = $this->clock->now()->getTimestamp();
            $this->store->revokePurposeTokens($purpose,$userId,$now);
            $token = Crypto::token();
            $id = $this->store->insertToken($purpose,Crypto::hash($token),$userId,$now+$seconds,$now);
            return ['id' => $id, 'token' => $token, 'expires_at' => $now+$seconds];
        });
    }

    public function consume(string $purpose, string $token): array
    {
        if (!$this->db->inTransaction()) { throw new \LogicException('Tokenverbrauch benötigt eine fachliche Transaktion.'); }
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            throw new \DomainException('Der Link ist ungültig oder abgelaufen.');
        }
        $now = $this->clock->now()->getTimestamp();
        $row = $this->store->validToken($purpose,Crypto::hash($token),$now);
        if ($row === null) { throw new \DomainException('Der Link ist ungültig oder abgelaufen.'); }
        $this->store->consumeToken((int)$row['id'],$now);
        return $row;
    }
}
