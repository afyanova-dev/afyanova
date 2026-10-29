<?php

declare(strict_types=1);

namespace App\Shared\Support;

use Closure;

/**
 * The causation id for the current unit of work (IMPLEMENTATION-CONTRACTS.md §19):
 * the id of the command or event that DIRECTLY caused the one now executing.
 *
 * Set by the command bus for the duration of a dispatch and by the outbox
 * relay's listener job (to the delivered event's id), so an audit row, an
 * outbox event, or a nested command written during that work carries the right
 * `causation_id` without every call site threading it. Unlike the correlation
 * id it has no generated fallback — no cause means null.
 */
final class Causation
{
    private static ?string $value = null;

    public static function set(?string $value): void
    {
        self::$value = $value;
    }

    public static function get(): ?string
    {
        return self::$value;
    }

    public static function reset(): void
    {
        self::$value = null;
    }

    /**
     * Run `$fn` with `$value` as the active causation id, restoring the previous
     * value afterwards (nested dispatches).
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function within(?string $value, Closure $fn): mixed
    {
        $previous = self::$value;
        self::$value = $value;

        try {
            return $fn();
        } finally {
            self::$value = $previous;
        }
    }
}
