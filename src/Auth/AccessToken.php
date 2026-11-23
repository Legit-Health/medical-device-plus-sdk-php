<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Auth;

use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;

/**
 * A DPoP-bound access token and the key it is bound to. They are only useful together.
 */
final readonly class AccessToken
{
    public function __construct(
        public string $value,
        public Es256Key $dpopKey,
        public int $expiresAt,
    ) {
    }

    public function isUsableAt(int $now, int $margin): bool
    {
        return $this->expiresAt - $now > $margin;
    }
}
