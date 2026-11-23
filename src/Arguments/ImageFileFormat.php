<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Arguments;

enum ImageFileFormat: string
{
    case Jpeg = 'jpeg';
    case Jpg = 'jpg';
    case Png = 'png';
}
