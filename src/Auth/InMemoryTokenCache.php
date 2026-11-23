<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Auth;

/**
 * Keeps tokens for the life of the PHP process. Under PHP-FPM every worker has its own copy,
 * so use Psr6TokenCache with a shared pool in web applications.
 */
final class InMemoryTokenCache implements TokenCache
{
    /** @var array<string, AccessToken> */
    private array $tokens = [];

    public function get(string $key): ?AccessToken
    {
        return $this->tokens[$key] ?? null;
    }

    public function save(string $key, AccessToken $token, int $ttl): void
    {
        $this->tokens[$key] = $token;
    }

    public function delete(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
