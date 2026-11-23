<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Crypto;

/**
 * Builds DPoP proofs (RFC 9449). Every request needs a new proof: the server accepts each one once.
 */
final class DpopProof
{
    public static function create(
        Es256Key $key,
        string $method,
        string $url,
        int $issuedAt,
        ?string $accessToken = null,
        ?string $nonce = null,
    ): string {
        $claims = [
            'jti' => Jwt::randomId(),
            'htm' => strtoupper($method),
            'htu' => self::targetUri($url),
            'iat' => $issuedAt,
        ];
        if ($accessToken !== null) {
            $claims['ath'] = self::accessTokenHash($accessToken);
        }
        if ($nonce !== null) {
            $claims['nonce'] = $nonce;
        }

        return Jwt::signEs256(['typ' => 'dpop+jwt', 'jwk' => $key->publicJwk()], $claims, $key);
    }

    public static function accessTokenHash(string $accessToken): string
    {
        return Base64Url::encode(hash('sha256', $accessToken, true));
    }

    /**
     * The htu claim is the request URL without query and fragment.
     */
    public static function targetUri(string $url): string
    {
        return (string) preg_replace('/[?#].*$/s', '', $url);
    }
}
