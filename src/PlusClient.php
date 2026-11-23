<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus;

use DateTimeImmutable;
use JsonSerializable;
use LegitHealth\MedicalDevicePlus\Arguments\DiagnosisSupportArguments;
use LegitHealth\MedicalDevicePlus\Arguments\RequestOptions;
use LegitHealth\MedicalDevicePlus\Auth\AccessTokenProvider;
use LegitHealth\MedicalDevicePlus\Auth\AuthorizationServerClient;
use LegitHealth\MedicalDevicePlus\Auth\ClientCredentials;
use LegitHealth\MedicalDevicePlus\Auth\InMemoryTokenCache;
use LegitHealth\MedicalDevicePlus\Auth\SystemClock;
use LegitHealth\MedicalDevicePlus\Auth\TokenCache;
use LegitHealth\MedicalDevicePlus\Crypto\DpopProof;
use LegitHealth\MedicalDevicePlus\Exception\AuthorizationException;
use LegitHealth\MedicalDevicePlus\Exception\RequestException;
use LegitHealth\MedicalDevicePlus\Response\DiagnosisSupportResponse;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use UnexpectedValueException;

final class PlusClient
{
    private const DIAGNOSIS_SUPPORT = '/v1.0/clinical/diagnosis-support';
    private const REPORT_ID_HEADER = 'legithp-report-id';

    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        string $baseUrl,
        private readonly AccessTokenProvider $tokens,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param string $baseUrl e.g. https://plus-staging.legit.health
     * @param TokenCache|null $tokenCache use Psr6TokenCache with a shared pool under PHP-FPM
     */
    public static function create(
        string $baseUrl,
        ClientCredentials $credentials,
        ?TokenCache $tokenCache = null,
        ?HttpClientInterface $httpClient = null,
        ?ClockInterface $clock = null,
    ): self {
        $httpClient ??= HttpClient::create();
        $clock ??= new SystemClock();
        $tokens = new AccessTokenProvider(
            new AuthorizationServerClient($httpClient, $clock),
            $credentials,
            $tokenCache ?? new InMemoryTokenCache(),
            $clock,
        );

        return new self($httpClient, $baseUrl, $tokens, $clock);
    }

    /**
     * @throws RequestException       when Plus answers with an error or cannot be reached
     * @throws AuthorizationException when no access token can be obtained
     */
    public function diagnosisSupport(DiagnosisSupportArguments $arguments, ?RequestOptions $options = null): DiagnosisSupportResponse
    {
        [$json, $headers] = $this->post(self::DIAGNOSIS_SUPPORT, $arguments, $options);

        try {
            return DiagnosisSupportResponse::fromJson($json, $headers[self::REPORT_ID_HEADER][0] ?? null);
        } catch (UnexpectedValueException $exception) {
            throw new RequestException($exception->getMessage(), 200, $json, previous: $exception);
        }
    }

    /**
     * Sends a POST with a new DPoP proof for every attempt. Retries once with a new token when
     * the token is rejected, and once with the server nonce when Plus asks for one.
     *
     * @return array{0: array<mixed>, 1: array<string, list<string>>}
     */
    private function post(string $path, JsonSerializable $payload, ?RequestOptions $options): array
    {
        $url = $this->baseUrl . $path;
        $nonce = null;
        $tokenRetried = false;
        $nonceRetried = false;

        while (true) {
            $token = $this->tokens->get();
            $proof = DpopProof::create($token->dpopKey, 'POST', $url, $this->clock->now()->getTimestamp(), $token->value, $nonce);

            try {
                $response = $this->httpClient->request('POST', $url, [
                    'json' => $payload,
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'DPoP ' . $token->value,
                        'DPoP' => $proof,
                    ],
                    ...($options?->asArray() ?? []),
                ]);
                $status = $response->getStatusCode();
                $headers = $response->getHeaders(false);
                $body = $response->getContent(false);
            } catch (ExceptionInterface $exception) {
                throw new RequestException(sprintf('Could not send the request to %s: %s', $path, $exception->getMessage()), previous: $exception);
            }

            $content = json_decode($body, true);
            if ($status === 200) {
                if (!\is_array($content)) {
                    throw new RequestException(sprintf('The response of %s is not JSON', $path), $status);
                }

                return [$content, $headers];
            }

            $error = self::challengeError($headers['www-authenticate'][0] ?? null);
            if ($status === 401 && $error === 'use_dpop_nonce' && isset($headers['dpop-nonce'][0]) && !$nonceRetried) {
                $nonce = $headers['dpop-nonce'][0];
                $nonceRetried = true;
                continue;
            }
            if ($status === 401 && $error === 'invalid_token' && !$tokenRetried) {
                $this->tokens->invalidate();
                $tokenRetried = true;
                continue;
            }

            throw new RequestException(
                sprintf('Request to %s failed with status code %d%s', $path, $status, $error === null ? '' : sprintf(' (%s)', $error)),
                $status,
                \is_array($content) ? $content : null,
                $error,
                $this->retryAfter($headers['retry-after'][0] ?? null),
            );
        }
    }

    private static function challengeError(?string $wwwAuthenticate): ?string
    {
        if ($wwwAuthenticate === null || preg_match('/error="([^"]+)"/', $wwwAuthenticate, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function retryAfter(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $value);
        if ($date === false) {
            return null;
        }

        return max(0, $date->getTimestamp() - $this->clock->now()->getTimestamp());
    }
}
