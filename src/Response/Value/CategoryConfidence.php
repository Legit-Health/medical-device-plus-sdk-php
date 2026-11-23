<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

/**
 * A predicted category (image domain or modality) and its confidence from 0 to 100.
 */
final readonly class CategoryConfidence
{
    public function __construct(
        public string $category,
        public float $confidence,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function fromJson(array $json): self
    {
        return new self(Json::string($json, 'category'), Json::float($json, 'confidence'));
    }
}
