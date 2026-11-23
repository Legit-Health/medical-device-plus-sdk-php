<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Auth;

use Psr\Clock\ClockInterface;

/**
 * Returns a cached token while it has more than 30 seconds left, and asks for a new one otherwise.
 * There is no refresh token.
 */
final class AccessTokenProvider
{
    public const REFRESH_MARGIN = 30;

    public function __construct(
        private readonly AuthorizationServerClient $authorizationServer,
        private readonly ClientCredentials $credentials,
        private readonly TokenCache $cache = new InMemoryTokenCache(),
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
    }

    public function get(): AccessToken
    {
        $key = $this->credentials->cacheKey();
        $now = $this->clock->now()->getTimestamp();

        $token = $this->cache->get($key);
        if ($token !== null && $token->isUsableAt($now, self::REFRESH_MARGIN)) {
            return $token;
        }

        $token = $this->authorizationServer->requestToken($this->credentials);
        $this->cache->save($key, $token, $token->expiresAt - $now - self::REFRESH_MARGIN);

        return $token;
    }

    public function invalidate(): void
    {
        $this->cache->delete($this->credentials->cacheKey());
    }
}
