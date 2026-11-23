<?php

declare(strict_types=1);

namespace LegitHealth\MedicalDevicePlus\Response\Value;

use UnexpectedValueException;

/**
 * @internal typed access to decoded JSON
 */
final class Json
{
    /**
     * @param array<mixed> $json
     */
    public static function string(array $json, string $key): string
    {
        $value = $json[$key] ?? null;
        if (!\is_string($value)) {
            throw self::unexpected($key, 'a string');
        }

        return $value;
    }

    /**
     * @param array<mixed> $json
     */
    public static function optionalString(array $json, string $key): ?string
    {
        $value = $json[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw self::unexpected($key, 'a string');
        }

        return $value;
    }

    /**
     * @param array<mixed> $json
     */
    public static function float(array $json, string $key): float
    {
        $value = $json[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            throw self::unexpected($key, 'a number');
        }

        return (float) $value;
    }

    /**
     * @param array<mixed> $json
     */
    public static function bool(array $json, string $key): bool
    {
        $value = $json[$key] ?? null;
        if (!\is_bool($value)) {
            throw self::unexpected($key, 'a boolean');
        }

        return $value;
    }

    /**
     * @param array<mixed> $json
     * @return array<string, mixed>
     */
    public static function object(array $json, string $key): array
    {
        $value = $json[$key] ?? null;
        if (!\is_array($value)) {
            throw self::unexpected($key, 'an object');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<mixed> $json
     * @return array<string, mixed>|null
     */
    public static function optionalObject(array $json, string $key): ?array
    {
        return ($json[$key] ?? null) === null ? null : self::object($json, $key);
    }

    /**
     * @param array<mixed> $json
     * @return list<array<string, mixed>>
     */
    public static function list(array $json, string $key): array
    {
        $value = $json[$key] ?? null;
        if (!\is_array($value) || !array_is_list($value)) {
            throw self::unexpected($key, 'a list');
        }

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    /**
     * @param array<mixed> $json
     * @return list<mixed>
     */
    public static function optionalList(array $json, string $key): array
    {
        return ($json[$key] ?? null) === null ? [] : self::list($json, $key);
    }

    private static function unexpected(string $key, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException(sprintf('Unexpected response from Legit.Health Plus: "%s" should be %s', $key, $expected));
    }
}
