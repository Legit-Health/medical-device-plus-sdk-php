<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Exception;

use RuntimeException;
use Throwable;

/**
 * A request to Legit.Health Plus failed.
 */
final class RequestException extends RuntimeException
{
    /**
     * @param array<mixed>|null $content       decoded JSON body of the error response, when there is one
     * @param string|null       $error         the `error` of the `WWW-Authenticate: DPoP` challenge, on 401 and 403
     * @param int|null          $retryAfter    seconds to wait before retrying, from the `Retry-After` header
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $content = null,
        public readonly ?string $error = null,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * 503 with `Retry-After`: the device is busy. Retry after `$retryAfter` seconds.
     */
    public function isDeviceSaturated(): bool
    {
        return $this->statusCode === 503 && $this->retryAfter !== null;
    }

    /**
     * 503 without `Retry-After`: the device is not ready yet.
     */
    public function isDeviceNotReady(): bool
    {
        return $this->statusCode === 503 && $this->retryAfter === null;
    }

    /**
     * 504: the device took too long. Do not retry right away.
     */
    public function isDeviceTimeout(): bool
    {
        return $this->statusCode === 504;
    }
}
