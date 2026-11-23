<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Auth;

use LegitHealth\MedicalDevicePlus\Crypto\Es256Key;

/**
 * Identity of a machine client registered in the Legit.Health authorization server.
 */
final readonly class ClientCredentials
{
    public const DEFAULT_SCOPE = 'organization:*';

    public string $issuer;

    /**
     * @param string $issuer     exact issuer URL, e.g. https://id-staging.legit.health/realms/legit-health
     * @param string $clientId   e.g. app-backend
     * @param Es256Key $privateKey the client key registered in the authorization server (private_key_jwt)
     * @param string $scope      Plus scopes are granted by default; organization:* must always be requested
     */
    public function __construct(
        string $issuer,
        public string $clientId,
        public Es256Key $privateKey,
        public string $scope = self::DEFAULT_SCOPE,
    ) {
        $this->issuer = rtrim($issuer, '/');
    }

    public function cacheKey(): string
    {
        return 'legit_health_plus_token_' . hash('sha256', $this->issuer . '|' . $this->clientId . '|' . $this->scope);
    }
}
