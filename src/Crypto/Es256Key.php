<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Crypto;

use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A P-256 private key that signs with ES256 (ECDSA using P-256 and SHA-256, RFC 7518).
 */
final class Es256Key
{
    private const COORDINATE_LENGTH = 32;

    private function __construct(private readonly OpenSSLAsymmetricKey $key)
    {
    }

    public static function generate(): self
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($key === false) {
            throw new RuntimeException('Could not generate a P-256 key: ' . self::opensslError());
        }

        return new self($key);
    }

    /**
     * Accepts a PEM private key, or the same PEM encoded in base64 on a single line,
     * which is how it is stored in environment variables and secret managers.
     */
    public static function fromPem(string $pem): self
    {
        $pem = trim($pem);
        if (!str_contains($pem, '-----BEGIN')) {
            $decoded = base64_decode($pem, true);
            if ($decoded === false || !str_contains($decoded, '-----BEGIN')) {
                throw new InvalidArgumentException('The private key is neither a PEM nor a base64-encoded PEM');
            }
            $pem = $decoded;
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new InvalidArgumentException('Could not read the private key: ' . self::opensslError());
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidArgumentException('The private key must be an EC key on the P-256 (prime256v1) curve');
        }

        return new self($key);
    }

    public function toPem(): string
    {
        if (!openssl_pkey_export($this->key, $pem) || !\is_string($pem)) {
            throw new RuntimeException('Could not export the private key: ' . self::opensslError());
        }

        return $pem;
    }

    /**
     * @return array{kty: string, crv: string, x: string, y: string}
     */
    public function publicJwk(): array
    {
        $details = openssl_pkey_get_details($this->key);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('Could not read the public key coordinates');
        }

        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'x' => Base64Url::encode(self::padCoordinate($details['ec']['x'])),
            'y' => Base64Url::encode(self::padCoordinate($details['ec']['y'])),
        ];
    }

    /**
     * Returns the JWS signature: R and S concatenated, 64 bytes (RFC 7518, section 3.4).
     */
    public function sign(string $data): string
    {
        if (!openssl_sign($data, $der, $this->key, OPENSSL_ALGO_SHA256) || !\is_string($der)) {
            throw new RuntimeException('Could not sign: ' . self::opensslError());
        }

        return self::derToRaw($der);
    }

    private static function derToRaw(string $der): string
    {
        if (\strlen($der) < 8 || $der[0] !== "\x30") {
            throw new RuntimeException('Unexpected ECDSA signature encoding');
        }
        // P-256 signatures are at most 72 bytes, so the sequence length is always in short form.
        $offset = 2;
        $r = self::readInteger($der, $offset);
        $s = self::readInteger($der, $offset);

        return self::padCoordinate($r) . self::padCoordinate($s);
    }

    private static function readInteger(string $der, int &$offset): string
    {
        if (($der[$offset] ?? '') !== "\x02" || !isset($der[$offset + 1])) {
            throw new RuntimeException('Unexpected ECDSA signature encoding');
        }
        $length = \ord($der[$offset + 1]);
        $value = substr($der, $offset + 2, $length);
        $offset += 2 + $length;

        return ltrim($value, "\0");
    }

    private static function padCoordinate(string $value): string
    {
        $value = ltrim($value, "\0");
        if (\strlen($value) > self::COORDINATE_LENGTH) {
            throw new RuntimeException('Unexpected P-256 value length');
        }

        return str_pad($value, self::COORDINATE_LENGTH, "\0", STR_PAD_LEFT);
    }

    private static function opensslError(): string
    {
        $messages = [];
        while (($message = openssl_error_string()) !== false) {
            $messages[] = $message;
        }

        return $messages === [] ? 'unknown OpenSSL error' : implode('; ', $messages);
    }
}
