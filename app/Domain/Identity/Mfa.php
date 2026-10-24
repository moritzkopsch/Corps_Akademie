<?php
declare(strict_types=1);
namespace Corpsakademie\Domain\Identity;

use Corpsakademie\Infrastructure\{Audit,Config,Crypto,Database,IdentityRepository};
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

final class Mfa
{
    public function __construct(private readonly Database $db, private readonly Crypto $crypto, private readonly Config $config, private readonly ClockInterface $clock, private readonly Audit $audit, private readonly IdentityRepository $store) {}

    public function factor(int $userId): ?array
    {
        return $this->store->factor($userId);
    }

    public function newSecret(): string { return TOTP::generate($this->clock, 32)->getSecret(); }

    public function uri(string $secret, string $email): string
    {
        return TOTP::createFromSecret($secret,$this->clock)->withLabel($email)->withIssuer('Corpsakademie')->getProvisioningUri();
    }

    private function counter(string $secret, string $code): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D', $code)) { return null; }
        $otp = TOTP::createFromSecret($secret, $this->clock);
        $current = intdiv($this->clock->now()->getTimestamp(), 30);
        foreach ([$current, $current-1, $current+1] as $counter) {
            if ($counter >= 0 && hash_equals($otp->at($counter*30), $code)) { return $counter; }
        }
        return null;
    }

    public function enroll(int $userId, string $secret, string $code, ?string $keepSessionHash = null): array
    {
        $counter = $this->counter($secret,$code);
        if ($counter === null) { throw new \DomainException('Der Code ist ungültig. Bitte den aktuellen sechsstelligen Code eingeben.'); }
        return $this->db->immediate(function () use ($userId,$secret,$counter,$keepSessionHash): array {
            if ($this->factor($userId) !== null) { throw new \DomainException('Für dieses Konto ist bereits eine Authenticator-App eingerichtet.'); }
            $now = $this->clock->now()->getTimestamp();
            $this->store->insertFactor($userId,$this->crypto->encrypt($secret,'mfa'),$this->config->keyId,$counter,$now);
            $codes = [];
            for ($i=0; $i<10; $i++) {
                $raw = bin2hex(random_bytes(16));
                $codes[] = implode('-', str_split($raw, 8));
                $this->store->insertRecoveryCode($userId,Crypto::hash($raw),$now);
            }
            // Enrollment elevates the account; all other sessions lose access.
            $this->store->revokeSessions($userId,$now,$keepSessionHash);
            $this->audit->record('mfa.enrolled',$userId);
            return $codes;
        });
    }

    public function verify(int $userId, string $code): bool
    {
        return $this->db->immediate(function () use ($userId,$code): bool {
            $factor = $this->factor($userId);
            if ($factor === null) { return false; }
            if (preg_match('/^[0-9]{6}$/D',$code)) {
                $secret = $this->crypto->decrypt($factor['secret_ciphertext'],'mfa',$factor['key_id']);
                $counter = $this->counter($secret,$code);
                if ($counter === null || $counter <= (int) $factor['last_counter']) { return false; }
                return $this->store->advanceCounter((int)$factor['id'],$counter);
            }
            $normalized = strtolower(str_replace(['-', ' '], '', $code));
            if (!preg_match('/^[a-f0-9]{32}$/D',$normalized)) { return false; }
            $used = $this->store->consumeRecoveryCode($userId,Crypto::hash($normalized),$this->clock->now()->getTimestamp());
            if ($used) { $this->audit->record('mfa.recovery_used',$userId); }
            return $used;
        });
    }
}
