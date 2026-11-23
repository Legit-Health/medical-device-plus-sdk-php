<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Crypto;

final class Jwt
{
    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public static function signEs256(array $header, array $claims, Es256Key $key): string
    {
        $signingInput = self::encodeSegment(['alg' => 'ES256', ...$header]) . '.' . self::encodeSegment($claims);

        return $signingInput . '.' . Base64Url::encode($key->sign($signingInput));
    }

    public static function randomId(): string
    {
        return Base64Url::encode(random_bytes(24));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encodeSegment(array $data): string
    {
        return Base64Url::encode(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
