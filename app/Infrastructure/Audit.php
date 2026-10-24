<?php
declare(strict_types=1);
namespace Corpsakademie\Infrastructure;

use Psr\Clock\ClockInterface;

final class Audit
{
    public function __construct(private readonly Database $db, private readonly ClockInterface $clock) {}

    public function record(string $action, ?int $actor, string $publicId = '', array $details = []): void
    {
        $this->db->run('INSERT INTO audit_logs(actor_user_id,action,entity_type,entity_public_id,changes_redacted,correlation_id,occurred_at) VALUES(?,?,?,?,?,?,?)', [
            $actor, $action, 'user', $publicId, json_encode($details, JSON_THROW_ON_ERROR), bin2hex(random_bytes(12)), $this->clock->now()->getTimestamp(),
        ]);
    }

    public function security(string $type, string $subjectHash, ?int $actor = null): void
    {
        $now = $this->clock->now()->getTimestamp();
        $this->db->run('INSERT INTO security_events(actor_user_id,type,severity,subject_hmac,details_redacted,occurred_at,retain_until) VALUES(?,?,?,?,?,?,?)', [
            $actor, $type, 'notice', $subjectHash, '{}', $now, $now + 90 * 86400,
        ]);
    }
}
