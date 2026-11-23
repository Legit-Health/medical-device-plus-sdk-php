<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Arguments;

use JsonSerializable;

final readonly class EncodedImage implements JsonSerializable
{
    /**
     * @param string $data base64-encoded image
     */
    public function __construct(
        public string $data,
        public ImageFileFormat $fileFormat = ImageFileFormat::Jpeg,
        public ImageColorModel $colorModel = ImageColorModel::Rgb,
        public ?string $identifier = null,
    ) {
    }

    public static function fromBinary(string $bytes, ImageFileFormat $fileFormat = ImageFileFormat::Jpeg, ?string $identifier = null): self
    {
        return new self(base64_encode($bytes), $fileFormat, identifier: $identifier);
    }

    /**
     * @return array<string, string>
     */
    public function jsonSerialize(): array
    {
        $json = [
            'data' => $this->data,
            'fileFormat' => $this->fileFormat->value,
            'colorModel' => $this->colorModel->value,
        ];
        if ($this->identifier !== null) {
            $json['identifier'] = $this->identifier;
        }

        return $json;
    }
}
