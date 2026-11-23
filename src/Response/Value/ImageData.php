<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

final readonly class ImageData
{
    public function __construct(
        public string $data,
        public string $fileFormat,
        public string $colorModel,
        public ?string $identifier,
    ) {
    }

    /**
     * @param array<string, mixed> $json
     */
    public static function fromJson(array $json): self
    {
        return new self(
            Json::string($json, 'data'),
            Json::optionalString($json, 'fileFormat') ?? 'jpeg',
            Json::optionalString($json, 'colorModel') ?? 'rgb',
            Json::optionalString($json, 'identifier'),
        );
    }
}
