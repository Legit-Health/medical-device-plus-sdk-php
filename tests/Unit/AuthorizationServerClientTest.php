<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Tests\Unit;

use LegitHealth\MedicalDevicePlus\Auth\AuthorizationServerClient;
use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;
use LegitHealth\MedicalDevicePlus\Exception\AuthorizationException;
use LegitHealth\MedicalDevicePlus\Tests\Support\FakeClock;
use LegitHealth\MedicalDevicePlus\Tests\Support\Fixtures;
use LegitHealth\MedicalDevicePlus\Tests\Support\JwtInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AuthorizationServerClientTest extends TestCase
{
    public function testRequestsADpopBoundTokenWithPrivateKeyJwt(): void
    {
        $clientKey = Es256Key::generate();
        $clock = new FakeClock();
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return count($requests) === 1 ? Fixtures::discovery() : Fixtures::token();
        });

        $token = (new AuthorizationServerClient($http, $clock))->requestToken(Fixtures::credentials($clientKey));

        self::assertSame('access-token-1', $token->value);
        self::assertSame($clock->timestamp + 300, $token->expiresAt);

        self::assertSame('GET', $requests[0]['method']);
        self::assertSame(Fixtures::ISSUER . '/.well-known/openid-configuration', $requests[0]['url']);

        $tokenRequest = $requests[1];
        self::assertSame('POST', $tokenRequest['method']);
        self::assertSame(Fixtures::TOKEN_ENDPOINT, $tokenRequest['url']);
        parse_str($tokenRequest['options']['body'], $form);
        self::assertSame('client_credentials', $form['grant_type']);
        self::assertSame('app-backend', $form['client_id']);
        self::assertSame('organization:*', $form['scope']);
        self::assertSame('urn:ietf:params:oauth:client-assertion-type:jwt-bearer', $form['client_assertion_type']);
        self::assertArrayNotHasKey('client_secret', $form);

        $assertion = JwtInspector::decode($form['client_assertion']);
        self::assertTrue(JwtInspector::verifyWithKey($form['client_assertion'], $clientKey));
        self::assertSame('ES256', $assertion['header']['alg']);
        self::assertSame('app-backend', $assertion['claims']['iss']);
        self::assertSame('app-backend', $assertion['claims']['sub']);
        self::assertSame(Fixtures::ISSUER, $assertion['claims']['aud']);
        self::assertSame($clock->timestamp, $assertion['claims']['iat']);
        self::assertSame($clock->timestamp + 60, $assertion['claims']['exp']);
        self::assertNotEmpty($assertion['claims']['jti']);

        $dpop = self::header($tokenRequest['options'], 'dpop');
        $proof = JwtInspector::decode($dpop);
        self::assertTrue(JwtInspector::verifyWithEmbeddedJwk($dpop));
        self::assertSame('dpop+jwt', $proof['header']['typ']);
        self::assertSame('ES256', $proof['header']['alg']);
        self::assertSame($token->dpopKey->publicJwk(), $proof['header']['jwk']);
        self::assertNotSame($clientKey->publicJwk(), $proof['header']['jwk'], 'the DPoP key must not be the client key');
        self::assertSame('POST', $proof['claims']['htm']);
        self::assertSame(Fixtures::TOKEN_ENDPOINT, $proof['claims']['htu']);
        self::assertArrayNotHasKey('ath', $proof['claims']);
    }

    public function testRejectsADiscoveryDocumentOfAnotherIssuer(): void
    {
        $http = new MockHttpClient([Fixtures::discovery('https://evil.example/realms/legit-health')]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('expected "' . Fixtures::ISSUER . '"');
        (new AuthorizationServerClient($http))->requestToken(Fixtures::credentials());
    }

    public function testRejectsBearerTokens(): void
    {
        $http = new MockHttpClient([
            Fixtures::discovery(),
            Fixtures::json(['access_token' => 'x', 'token_type' => 'Bearer', 'expires_in' => 300]),
        ]);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('not a DPoP token');
        (new AuthorizationServerClient($http))->requestToken(Fixtures::credentials());
    }

    public function testRetriesOnceWithTheServerNonce(): void
    {
        $proofs = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$proofs): MockResponse {
            if ($method === 'GET') {
                return Fixtures::discovery();
            }
            $proofs[] = JwtInspector::decode(self::header($options, 'dpop'))['claims'];

            return count($proofs) === 1
                ? Fixtures::json(['error' => 'use_dpop_nonce'], 400, ['dpop-nonce' => 'server-nonce'])
                : Fixtures::token();
        });

        (new AuthorizationServerClient($http))->requestToken(Fixtures::credentials());

        self::assertCount(2, $proofs);
        self::assertArrayNotHasKey('nonce', $proofs[0]);
        self::assertSame('server-nonce', $proofs[1]['nonce']);
        self::assertNotSame($proofs[0]['jti'], $proofs[1]['jti']);
    }

    public function testReportsTokenEndpointErrors(): void
    {
        $http = new MockHttpClient([
            Fixtures::discovery(),
            Fixtures::json(['error' => 'invalid_client', 'error_description' => 'Invalid client credentials'], 401),
        ]);

        try {
            (new AuthorizationServerClient($http))->requestToken(Fixtures::credentials());
            self::fail('expected an exception');
        } catch (AuthorizationException $exception) {
            self::assertSame(401, $exception->statusCode);
            self::assertStringContainsString('invalid_client', $exception->getMessage());
        }
    }

    public function testCachesTheDiscoveryDocument(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function (string $method) use (&$calls): MockResponse {
            if ($method === 'GET') {
                ++$calls;

                return Fixtures::discovery();
            }

            return Fixtures::token();
        });
        $client = new AuthorizationServerClient($http);
        $client->requestToken(Fixtures::credentials());
        $client->requestToken(Fixtures::credentials());

        self::assertSame(1, $calls);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function header(array $options, string $name): string
    {
        foreach ($options['headers'] as $line) {
            [$key, $value] = explode(': ', $line, 2);
            if (strtolower($key) === $name) {
                return $value;
            }
        }
        self::fail("header $name not sent");
    }
}
