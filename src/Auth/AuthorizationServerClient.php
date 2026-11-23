<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Auth;

use LegitHealth\MedicalDevicePlus\Crypto\DpopProof;
use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;
use LegitHealth\MedicalDevicePlus\Crypto\Jwt;
use LegitHealth\MedicalDevicePlus\Exception\AuthorizationException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gets DPoP-bound access tokens from the Legit.Health authorization server with the
 * client_credentials grant and private_key_jwt client authentication (RFC 7523, ES256).
 */
final class AuthorizationServerClient
{
    private const CLIENT_ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
    private const CLIENT_ASSERTION_LIFETIME = 60;

    /** @var array<string, string> token endpoint by issuer */
    private array $tokenEndpoints = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    public function requestToken(ClientCredentials $credentials): AccessToken
    {
        $tokenEndpoint = $this->tokenEndpoint($credentials->issuer);
        $dpopKey = Es256Key::generate();

        $nonce = null;
        while (true) {
            $now = $this->clock->now()->getTimestamp();
            try {
                $response = $this->httpClient->request('POST', $tokenEndpoint, [
                    'headers' => [
                        'Accept' => 'application/json',
                        'DPoP' => DpopProof::create($dpopKey, 'POST', $tokenEndpoint, $now, nonce: $nonce),
                    ],
                    'body' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $credentials->clientId,
                        'scope' => $credentials->scope,
                        'client_assertion_type' => self::CLIENT_ASSERTION_TYPE,
                        'client_assertion' => $this->clientAssertion($credentials, $now),
                    ],
                ]);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $content = self::decode($response->getContent(false));
            } catch (ExceptionInterface $exception) {
                throw new AuthorizationException('Could not reach the token endpoint: ' . $exception->getMessage(), previous: $exception);
            }

            // Retry once with the server nonce; a second request for a nonce ends as an error below.
            if ($status === 400 && ($content['error'] ?? null) === 'use_dpop_nonce' && isset($headers['dpop-nonce'][0]) && $nonce === null) {
                $nonce = $headers['dpop-nonce'][0];
                continue;
            }

            if ($status !== 200) {
                $error = \is_string($content['error'] ?? null) ? $content['error'] : 'unknown_error';
                $description = \is_string($content['error_description'] ?? null) ? ': ' . $content['error_description'] : '';
                throw new AuthorizationException(sprintf('The token endpoint answered %d (%s%s)', $status, $error, $description), $status, $content);
            }

            return self::accessTokenFrom($content, $dpopKey, $now);
        }
    }

    private function clientAssertion(ClientCredentials $credentials, int $now): string
    {
        return Jwt::signEs256(['typ' => 'JWT'], [
            'iss' => $credentials->clientId,
            'sub' => $credentials->clientId,
            // The audience is the issuer, not the token endpoint.
            'aud' => $credentials->issuer,
            'jti' => Jwt::randomId(),
            'iat' => $now,
            'exp' => $now + self::CLIENT_ASSERTION_LIFETIME,
        ], $credentials->privateKey);
    }

    private function tokenEndpoint(string $issuer): string
    {
        if (isset($this->tokenEndpoints[$issuer])) {
            return $this->tokenEndpoints[$issuer];
        }

        try {
            $response = $this->httpClient->request('GET', $issuer . '/.well-known/openid-configuration', [
                'headers' => ['Accept' => 'application/json'],
            ]);
            $status = $response->getStatusCode();
            $document = self::decode($response->getContent(false));
        } catch (ExceptionInterface $exception) {
            throw new AuthorizationException('Could not read the OpenID configuration: ' . $exception->getMessage(), previous: $exception);
        }

        if ($status !== 200) {
            throw new AuthorizationException(sprintf('The OpenID configuration answered %d', $status), $status, $document);
        }
        if (($document['issuer'] ?? null) !== $issuer) {
            throw new AuthorizationException(sprintf('The OpenID configuration belongs to issuer "%s", expected "%s"', \is_string($document['issuer'] ?? null) ? $document['issuer'] : '', $issuer));
        }
        if (!\is_string($document['token_endpoint'] ?? null) || !str_starts_with($document['token_endpoint'], 'https://')) {
            throw new AuthorizationException('The OpenID configuration has no https token_endpoint');
        }

        return $this->tokenEndpoints[$issuer] = $document['token_endpoint'];
    }

    /**
     * @param array<mixed> $content
     */
    private static function accessTokenFrom(array $content, Es256Key $dpopKey, int $now): AccessToken
    {
        if (!\is_string($content['access_token'] ?? null) || $content['access_token'] === '') {
            throw new AuthorizationException('The token response has no access_token', 200);
        }
        if (strcasecmp(\is_string($content['token_type'] ?? null) ? $content['token_type'] : '', 'DPoP') !== 0) {
            throw new AuthorizationException('The token response is not a DPoP token', 200);
        }
        if (!\is_int($content['expires_in'] ?? null)) {
            throw new AuthorizationException('The token response has no expires_in', 200);
        }

        return new AccessToken($content['access_token'], $dpopKey, $now + $content['expires_in']);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);

        return \is_array($decoded) ? $decoded : [];
    }
}
