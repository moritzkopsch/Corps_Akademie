<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

/** PostgreSQL persistence for the identity aggregate; no password/token policy here. */
final class IdentityRepository
{
    public function __construct(private readonly Database $db) {}

    public function user(int $id): ?array { return $this->db->one('SELECT * FROM users WHERE id=?',[$id]); }
    public function userByEmail(string $key): ?array { return $this->db->one('SELECT * FROM users WHERE email_key=?',[$key]); }
    public function userCount(): int { return (int)$this->db->run('SELECT count(*) FROM users')->fetchColumn(); }

    public function insertUser(string $publicId,string $email,string $emailKey,string $name,string $hash,?int $verified,int $now): int
    {
        return (int)$this->db->run('INSERT INTO users(public_id,email,email_key,name,password_hash,status,email_verified_at,password_changed_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?) RETURNING id',[
            $publicId,$email,$emailKey,$name,$hash,'active',$verified,$now,$now,$now,
        ])->fetchColumn();
    }

    public function addRole(int $id,string $role): void
    {
        $this->db->run('INSERT INTO user_roles(user_id,role_id) SELECT ?,id FROM roles WHERE code=?',[$id,$role]);
    }

    public function hasPermission(int $id,string $permission): bool
    {
        return $this->db->one('SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN role_permissions rp ON rp.role_id=ur.role_id JOIN permissions p ON p.id=rp.permission_id WHERE u.id=? AND u.status=? AND p.code=? LIMIT 1',[$id,'active',$permission])!==null;
    }

    public function hasAdministrativeRole(int $id): bool
    {
        return $this->db->one("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.code IN ('superadmin','admin')",[$id])!==null;
    }

    public function roles(int $id): array
    {
        return array_column($this->db->run('SELECT r.label FROM roles r JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=? ORDER BY r.id',[$id])->fetchAll(),'label');
    }

    public function isSuperadmin(int $id): bool
    {
        return $this->db->one("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.code='superadmin'",[$id])!==null;
    }

    public function activeSuperadmins(): int
    {
        return (int)$this->db->run("SELECT count(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id WHERE r.code='superadmin' AND u.status='active'")->fetchColumn();
    }

    public function disable(int $id,int $now): void
    {
        $this->db->run("UPDATE users SET status='disabled',disabled_at=?,updated_at=?,row_version=row_version+1 WHERE id=?",[$now,$now,$id]);
    }

    public function replacePasswordHash(int $id,string $hash): void { $this->db->run('UPDATE users SET password_hash=? WHERE id=?',[$hash,$id]); }

    public function resetPassword(int $id,string $hash,int $now): void
    {
        $this->db->run('UPDATE users SET password_hash=?,password_changed_at=?,email_verified_at=COALESCE(email_verified_at,?),updated_at=?,row_version=row_version+1 WHERE id=?',[$hash,$now,$now,$now,$id]);
    }

    public function verifyEmail(int $id,int $now): void
    {
        $this->db->run('UPDATE users SET email_verified_at=?,updated_at=?,row_version=row_version+1 WHERE id=?',[$now,$now,$id]);
    }

    public function incrementBucket(string $scope,string $hash,int $window,int $expiry): int
    {
        $this->db->run('INSERT INTO rate_limit_buckets(scope,subject_hmac,window_start,hits,expires_at) VALUES(?,?,?,1,?) ON CONFLICT(scope,subject_hmac,window_start) DO UPDATE SET hits=hits+1',[$scope,$hash,$window,$expiry]);
        return (int)$this->db->run('SELECT hits FROM rate_limit_buckets WHERE scope=? AND subject_hmac=? AND window_start=?',[$scope,$hash,$window])->fetchColumn();
    }

    public function revokePurposeTokens(string $purpose,?int $userId,int $now): void
    {
        $this->db->run('UPDATE email_verification_tokens SET revoked_at=? WHERE purpose=? AND user_id IS NOT DISTINCT FROM ? AND consumed_at IS NULL AND revoked_at IS NULL',[$now,$purpose,$userId]);
    }

    public function insertToken(string $purpose,string $hash,?int $userId,int $expiry,int $now): int
    {
        return (int)$this->db->run('INSERT INTO email_verification_tokens(purpose,token_hash,user_id,expires_at,created_at) VALUES(?,?,?,?,?) RETURNING id',[$purpose,$hash,$userId,$expiry,$now])->fetchColumn();
    }

    public function validToken(string $purpose,string $hash,int $now): ?array
    {
        // FOR UPDATE serialisiert konkurrierende Einlösungen; der Aufruf erfolgt ausschließlich in einer Schreibtransaktion.
        return $this->db->one('SELECT * FROM email_verification_tokens WHERE purpose=? AND token_hash=? AND consumed_at IS NULL AND revoked_at IS NULL AND expires_at>? FOR UPDATE',[$purpose,$hash,$now]);
    }

    public function consumeToken(int $id,int $now): void { $this->db->run('UPDATE email_verification_tokens SET consumed_at=? WHERE id=? AND consumed_at IS NULL',[$now,$id]); }
    public function revokeTokens(int $userId,int $now): void { $this->db->run('UPDATE email_verification_tokens SET revoked_at=? WHERE user_id=? AND consumed_at IS NULL AND revoked_at IS NULL',[$now,$userId]); }

    public function factor(int $id): ?array { return $this->db->one('SELECT * FROM user_mfa_factors WHERE user_id=? AND disabled_at IS NULL',[$id]); }

    public function insertFactor(int $userId,string $ciphertext,string $keyId,int $counter,int $now): void
    {
        $this->db->run('INSERT INTO user_mfa_factors(user_id,kind,secret_ciphertext,key_id,confirmed_at,last_counter,created_at) VALUES(?,?,?,?,?,?,?)',[$userId,'totp',$ciphertext,$keyId,$now,$counter,$now]);
    }

    public function insertRecoveryCode(int $userId,string $hash,int $now): void
    {
        $this->db->run('INSERT INTO mfa_recovery_codes(user_id,code_hash,created_at) VALUES(?,?,?)',[$userId,$hash,$now]);
    }

    public function advanceCounter(int $factorId,int $counter): bool
    {
        return $this->db->run('UPDATE user_mfa_factors SET last_counter=? WHERE id=? AND last_counter<? AND disabled_at IS NULL',[$counter,$factorId,$counter])->rowCount()===1;
    }

    public function consumeRecoveryCode(int $userId,string $hash,int $now): bool
    {
        return $this->db->run('UPDATE mfa_recovery_codes SET used_at=? WHERE user_id=? AND code_hash=? AND used_at IS NULL',[$now,$userId,$hash])->rowCount()===1;
    }

    public function revokeSessions(int $userId,int $now,?string $exceptHash=null): void
    {
        $this->db->run('UPDATE sessions SET revoked_at=? WHERE user_id=? AND revoked_at IS NULL AND session_hash IS DISTINCT FROM ?',[$now,$userId,$exceptHash]);
    }

    public function activeSession(string $hash,int $now): ?array
    {
        return $this->db->one('SELECT s.* FROM sessions s LEFT JOIN users u ON u.id=s.user_id WHERE s.session_hash=? AND s.revoked_at IS NULL AND s.idle_expires_at>? AND s.absolute_expires_at>? AND (s.user_id IS NULL OR u.status=?)',[$hash,$now,$now,'active']);
    }

    public function touchSession(int $id,int $now,int $idleExpiry): void
    {
        $this->db->run('UPDATE sessions SET last_seen_at=?,idle_expires_at=LEAST(absolute_expires_at,?) WHERE id=? AND revoked_at IS NULL',[$now,$idleExpiry,$id]);
    }

    public function revokeSession(string $hash,int $now): void { $this->db->run('UPDATE sessions SET revoked_at=? WHERE session_hash=? AND revoked_at IS NULL',[$now,$hash]); }

    public function insertSession(?int $userId,string $hash,string $ciphertext,string $keyId,int $now,int $idleExpiry,int $absoluteExpiry): void
    {
        $this->db->run('INSERT INTO sessions(user_id,session_hash,payload_ciphertext,key_id,last_seen_at,idle_expires_at,absolute_expires_at,created_at) VALUES(?,?,?,?,?,?,?,?)',[$userId,$hash,$ciphertext,$keyId,$now,$idleExpiry,$absoluteExpiry,$now]);
    }

    public function saveSession(string $hash,string $ciphertext): void { $this->db->run('UPDATE sessions SET payload_ciphertext=? WHERE session_hash=? AND revoked_at IS NULL',[$ciphertext,$hash]); }
    public function cancelQueuedMail(int $userId): void { $this->db->run("UPDATE notification_outbox SET status='cancelled',secret_payload_ciphertext=NULL WHERE user_id=? AND status='queued'",[$userId]); }

    public function overview(): array
    {
        return $this->db->run("SELECT u.public_id,u.name,u.email,u.status,u.email_verified_at,string_agg(r.label, ', ') AS roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id GROUP BY u.id ORDER BY u.created_at DESC,u.id DESC LIMIT 100")->fetchAll();
    }

    public function statistics(): array
    {
        return $this->db->one("SELECT count(*) AS total,count(*) FILTER (WHERE status='active') AS active,count(*) FILTER (WHERE email_verified_at IS NOT NULL) AS verified FROM users");
    }
}
