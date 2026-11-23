<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class FakeClock implements ClockInterface
{
    public function __construct(public int $timestamp = 1_800_000_000)
    {
    }

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable())->setTimestamp($this->timestamp);
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}
