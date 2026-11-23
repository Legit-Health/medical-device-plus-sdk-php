<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Auth;

interface TokenCache
{
    public function get(string $key): ?AccessToken;

    public function save(string $key, AccessToken $token, int $ttl): void;

    public function delete(string $key): void;
}
