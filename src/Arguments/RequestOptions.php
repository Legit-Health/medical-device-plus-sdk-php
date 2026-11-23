<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Arguments;

final readonly class RequestOptions
{
    /**
     * @param float|null $timeout idle timeout in seconds
     * @param float|null $maxDuration maximum total duration of the request in seconds
     */
    public function __construct(
        public ?float $timeout = null,
        public ?float $maxDuration = null,
    ) {
    }

    /**
     * @return array<string, float>
     */
    public function asArray(): array
    {
        return array_filter([
            'timeout' => $this->timeout,
            'max_duration' => $this->maxDuration,
        ], static fn (?float $value): bool => $value !== null);
    }
}
