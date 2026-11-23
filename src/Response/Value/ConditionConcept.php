<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

final readonly class ConditionConcept
{
    public function __construct(
        public string $name,
        public string $code,
        public string $terminologySystem,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function fromJson(array $json): self
    {
        return new self(Json::string($json, 'name'), Json::string($json, 'code'), Json::string($json, 'terminologySystem'));
    }
}
