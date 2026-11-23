<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

final readonly class ImageTechnicalAnalysis
{
    /**
     * @param CategoryConfidence $imageDomain dermatological or non_dermatological
     * @param CategoryConfidence|null $imageModality clinical or dermoscopic
     */
    public function __construct(
        public bool $isAssessable,
        public ImageQuality $imageQuality,
        public CategoryConfidence $imageDomain,
        public ?CategoryConfidence $imageModality,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function fromJson(array $json): self
    {
        $modality = Json::optionalObject($json, 'imageModality');

        return new self(
            Json::bool($json, 'isAssessable'),
            ImageQuality::fromJson(Json::object($json, 'imageQuality')),
            CategoryConfidence::fromJson(Json::object($json, 'imageDomain')),
            $modality === null ? null : CategoryConfidence::fromJson($modality),
        );
    }
}
