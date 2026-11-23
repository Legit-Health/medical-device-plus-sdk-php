<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

final readonly class PerImageAnalysis
{
    public function __construct(
        public ImageData $sourceImage,
        public ImageTechnicalAnalysis $technicalSummary,
        public DifferentialDiagnosis $findings,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function fromJson(array $json): self
    {
        return new self(
            ImageData::fromJson(Json::object($json, 'sourceImage')),
            ImageTechnicalAnalysis::fromJson(Json::object($json, 'technicalSummary')),
            DifferentialDiagnosis::fromJson(Json::object($json, 'findings')),
        );
    }
}
