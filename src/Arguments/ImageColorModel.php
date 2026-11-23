<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Arguments;

enum ImageColorModel: string
{
    case Rgb = 'rgb';
    case Grayscale = 'grayscale';
    case Float = 'float';
}
