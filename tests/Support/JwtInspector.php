<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Tests\Support;

use LegitHealth\MedicalDevicePlus\Crypto\Base64Url;
use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;

/**
 * Decodes and verifies ES256 JWTs in tests, independently of the code under test.
 */
final class JwtInspector
{
    /**
     * @return array{header: array<string, mixed>, claims: array<string, mixed>}
     */
    public static function decode(string $jwt): array
    {
        [$header, $claims] = explode('.', $jwt);

        return [
            'header' => json_decode(Base64Url::decode($header), true, flags: JSON_THROW_ON_ERROR),
            'claims' => json_decode(Base64Url::decode($claims), true, flags: JSON_THROW_ON_ERROR),
        ];
    }

    public static function verifyWithKey(string $jwt, Es256Key $key): bool
    {
        $private = openssl_pkey_get_private($key->toPem());
        $details = openssl_pkey_get_details($private);

        return self::verify($jwt, $details['key']);
    }

    /**
     * Verifies with the public key embedded in the `jwk` header, as a DPoP server does.
     */
    public static function verifyWithEmbeddedJwk(string $jwt): bool
    {
        $jwk = self::decode($jwt)['header']['jwk'];
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')
            . "\x04" . Base64Url::decode($jwk['x']) . Base64Url::decode($jwk['y']);
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";

        return self::verify($jwt, $pem);
    }

    private static function verify(string $jwt, string $publicPem): bool
    {
        [$header, $claims, $signature] = explode('.', $jwt);
        $raw = Base64Url::decode($signature);
        if (strlen($raw) !== 64) {
            return false;
        }

        return openssl_verify($header . '.' . $claims, self::rawToDer($raw), $publicPem, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function rawToDer(string $raw): string
    {
        $encode = static function (string $int): string {
            $int = ltrim($int, "\0");
            if ($int === '' || ord($int[0]) > 0x7f) {
                $int = "\0" . $int;
            }

            return "\x02" . chr(strlen($int)) . $int;
        };
        $body = $encode(substr($raw, 0, 32)) . $encode(substr($raw, 32));

        return "\x30" . chr(strlen($body)) . $body;
    }
}
