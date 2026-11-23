<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

final readonly class ImageQuality
{
    /**
     * @param float $score from 0 to 10, 10 is best
     * @param string $interpretation bad, poor, acceptable, good or excellent
     * @param list<string> $qualityIssues blur, underexposed, overexposed, obstruction, framing, artifact or low-resolution
     */
    public function __construct(
        public float $score,
        public string $interpretation,
        public array $qualityIssues,
        public ?string $notesForReacquisition,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function fromJson(array $json): self
    {
        return new self(
            Json::float($json, 'score'),
            Json::string($json, 'interpretation'),
            array_values(array_filter(Json::optionalList($json, 'qualityIssues'), \is_string(...))),
            Json::optionalString($json, 'notesForReacquisition'),
        );
    }
}
