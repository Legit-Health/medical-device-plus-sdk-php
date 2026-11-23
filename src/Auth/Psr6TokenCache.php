<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Auth;

use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;
use Psr\Cache\CacheItemPoolInterface;
use Throwable;

/**
 * Stores the token together with its DPoP private key in a shared PSR-6 pool,
 * so that every PHP-FPM worker reuses the same token.
 */
final class Psr6TokenCache implements TokenCache
{
    public function __construct(private readonly CacheItemPoolInterface $pool)
    {
    }

    public function get(string $key): ?AccessToken
    {
        $item = $this->pool->getItem($key);
        if (!$item->isHit()) {
            return null;
        }
        $data = $item->get();
        if (!\is_array($data) || !\is_string($data['token'] ?? null) || !\is_string($data['dpop_key'] ?? null) || !\is_int($data['expires_at'] ?? null)) {
            return null;
        }

        try {
            return new AccessToken($data['token'], Es256Key::fromPem($data['dpop_key']), $data['expires_at']);
        } catch (Throwable) {
            return null;
        }
    }

    public function save(string $key, AccessToken $token, int $ttl): void
    {
        $item = $this->pool->getItem($key);
        $item->set([
            'token' => $token->value,
            'dpop_key' => $token->dpopKey->toPem(),
            'expires_at' => $token->expiresAt,
        ]);
        $item->expiresAfter(max(1, $ttl));
        $this->pool->save($item);
    }

    public function delete(string $key): void
    {
        $this->pool->deleteItem($key);
    }
}
