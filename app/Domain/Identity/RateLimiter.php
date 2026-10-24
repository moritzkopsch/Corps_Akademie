<?php
declare(strict_types=1);
namespace Corpsakademie\Domain\Identity;

use Corpsakademie\Infrastructure\{Crypto,Database,IdentityRepository};
use Psr\Clock\ClockInterface;

final class RateLimiter
{
    public function __construct(private readonly Database $db, private readonly Crypto $crypto, private readonly ClockInterface $clock, private readonly IdentityRepository $store) {}

    public function attempt(string $scope, string $subject, int $limit, int $seconds): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $window = intdiv($now, $seconds) * $seconds;
        $key = $this->crypto->subject($scope, $subject);
        return $this->db->immediate(function () use ($scope,$key,$window,$now,$seconds,$limit): bool {
            $hits = $this->store->incrementBucket($scope,$key,$window,$window+$seconds);
            return $hits <= $limit;
        });
    }
}
