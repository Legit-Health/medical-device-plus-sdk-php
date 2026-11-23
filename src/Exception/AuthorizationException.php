<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Exception;

use RuntimeException;
use Throwable;

/**
 * The authorization server did not issue an access token.
 */
final class AuthorizationException extends RuntimeException
{
    /**
     * @param array<mixed>|null $content
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $content = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
