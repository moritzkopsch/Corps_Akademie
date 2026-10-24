<?php
declare(strict_types=1);
namespace Corpsakademie\Tests\Support;

use Psr\Clock\ClockInterface;

final class TestClock implements ClockInterface
{
    public int $timestamp = 1792843200;
    public function now(): \DateTimeImmutable { return (new \DateTimeImmutable('@'.$this->timestamp))->setTimezone(new \DateTimeZone('UTC')); }
    public function advance(int $seconds): void { $this->timestamp += $seconds; }
}
