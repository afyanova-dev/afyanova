<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Illuminate\Support\Str;

/**
 * The correlation id for the current request / job (IMPLEMENTATION-CONTRACTS.md §19).
 * Flows into commands, events, jobs and every log line.
 */
final class CorrelationId
{
    private static ?string $value = null;

    public static function set(string $value): void
    {
        self::$value = $value;
    }

    public static function get(): string
    {
        return self::$value ??= (string) Str::uuid7();
    }

    public static function reset(): void
    {
        self::$value = null;
    }
}
