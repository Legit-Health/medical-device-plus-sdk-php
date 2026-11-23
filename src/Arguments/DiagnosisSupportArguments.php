<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Arguments;

use InvalidArgumentException;
use JsonSerializable;

final readonly class DiagnosisSupportArguments implements JsonSerializable
{
    public const MAX_IMAGES = 5;

    /**
     * @param list<EncodedImage> $images 1 to 5 images of the same lesion
     */
    public function __construct(public array $images)
    {
        $count = \count($images);
        if ($count < 1 || $count > self::MAX_IMAGES) {
            throw new InvalidArgumentException(sprintf('Diagnosis support needs between 1 and %d images, %d given', self::MAX_IMAGES, $count));
        }
    }

    /**
     * @return array{images: list<EncodedImage>}
     */
    public function jsonSerialize(): array
    {
        return ['images' => $this->images];
    }
}
