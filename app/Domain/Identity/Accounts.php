<?php
declare(strict_types=1);
namespace Corpsakademie\Domain\Identity;

use Corpsakademie\Infrastructure\{Audit,Crypto,Database,IdentityRepository};
use Psr\Clock\ClockInterface;

final class Accounts
{
    public function __construct(private readonly Database $db, private readonly Audit $audit, private readonly ClockInterface $clock, private readonly IdentityRepository $store) {}

    public static function emailKey(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    public static function checkPassword(string $password): void
    {
        if (mb_strlen($password, 'UTF-8') < 12 || mb_strlen($password, 'UTF-8') > 200 || str_contains($password, "\0")) {
            throw new \DomainException('Das Passwort muss zwischen 12 und 200 Zeichen lang sein.');
        }
    }

    public function create(string $email, string $name, string $password, string $role, bool $verified = false): array
    {
        $email = trim($email);
        $name = trim($name);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \DomainException('Bitte eine gültige E-Mail-Adresse eingeben.');
        }
        if (mb_strlen($name) < 1 || mb_strlen($name) > 120) {
            throw new \DomainException('Der Name muss zwischen 1 und 120 Zeichen lang sein.');
        }
        self::checkPassword($password);
        if (!in_array($role, ['superadmin','admin','instructor'], true)) {
            throw new \DomainException('Unbekannte Rolle.');
        }
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        return $this->db->immediate(function () use ($email, $name, $hash, $role, $verified): array {
            if ($this->findByEmail($email) !== null) {
                throw new \DomainException('Diese E-Mail-Adresse ist bereits vergeben.');
            }
            $now = $this->clock->now()->getTimestamp();
            $publicId = Crypto::token();
            $id = $this->store->insertUser($publicId,$email,self::emailKey($email),$name,$hash,$verified ? $now : null,$now);
            $this->store->addRole($id,$role);
            $this->audit->record('account.created', null, $publicId, ['role' => $role]);
            return $this->find($id);
        });
    }

    public function find(int $id): ?array { return $this->store->user($id); }
    public function findByEmail(string $email): ?array { return $this->store->userByEmail(self::emailKey($email)); }

    public function can(int $id, string $permission): bool
    {
        return $this->store->hasPermission($id,$permission);
    }

    public function requiresMfa(int $id): bool
    {
        return $this->can($id,'admin.access') || $this->can($id,'users.view') || $this->can($id,'users.manage')
            || $this->store->hasAdministrativeRole($id);
    }

    public function roles(int $id): array
    {
        return $this->store->roles($id);
    }

    public function disable(int $id): void
    {
        $this->db->immediate(function () use ($id): void {
            $user = $this->find($id);
            if ($user === null) { throw new \DomainException('Konto nicht gefunden.'); }
            $isSuper = $this->store->isSuperadmin($id);
            $count = $this->store->activeSuperadmins();
            if ($isSuper && $user['status'] === 'active' && $count <= 1) {
                throw new \DomainException('Der letzte aktive Superadministrator darf nicht deaktiviert werden.');
            }
            $now = $this->clock->now()->getTimestamp();
            $this->store->disable($id,$now);
            $this->store->revokeSessions($id,$now);
            $this->store->revokeTokens($id,$now);
            $this->audit->record('account.disabled', null, $user['public_id']);
        });
    }
}
