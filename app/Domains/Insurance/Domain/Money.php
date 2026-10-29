<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use InvalidArgumentException;

/**
 * Fixed-scale-2 decimal arithmetic for Insurance money amounts — the
 * `App\Domains\Billing\Domain\Money` / `App\Domains\Procurement\Domain\Money`
 * house precedent (each domain carries its own scalar helper). Domain-local,
 * NOT shared-kernel, NOT `#[TypeScript]`.
 *
 * Used ONLY for the ILLUSTRATIVE coverage-split estimate (`CoverageCalculator`)
 * — Insurance never posts a charge or a financial adjustment (ADR-112 /
 * ADR-198 D-C). Currency is carried separately; this helper is scalar-only.
 */
final class Money
{
    public const SCALE = 2;

    /**
     * Validate + normalise to a fixed-scale-2 numeric string.
     *
     * @return numeric-string
     */
    public static function of(string $value): string
    {
        if (is_numeric($value)) {
            return bcadd($value, '0', self::SCALE);
        }

        throw new InvalidArgumentException("Money [{$value}] is not numeric.");
    }

    /** @return numeric-string */
    public static function zero(): string
    {
        return bcadd('0', '0', self::SCALE);
    }

    /** @return numeric-string */
    public static function add(string $a, string $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    /** @return numeric-string */
    public static function subtract(string $a, string $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function isNegative(string $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) < 0;
    }

    public static function isZero(string $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) === 0;
    }

    /** `$a >= $b`, at money scale. */
    public static function gte(string $a, string $b): bool
    {
        return bccomp(self::of($a), self::of($b), self::SCALE) >= 0;
    }

    /**
     * `min($a, $b)` at money scale.
     *
     * @return numeric-string
     */
    public static function min(string $a, string $b): string
    {
        return self::gte($a, $b) ? self::of($b) : self::of($a);
    }

    /**
     * `max($value, 0)` — clamp a negative to zero.
     *
     * @return numeric-string
     */
    public static function clampToZero(string $value): string
    {
        return self::isNegative($value) ? self::zero() : self::of($value);
    }

    /**
     * `$amount × ($percent / 100)`, rounded HALF-UP to scale-2. `$percent` is
     * an integer 0..100 (`$amount` is non-negative in the coverage split), so
     * half-up is add-0.005-then-truncate.
     *
     * @return numeric-string
     */
    public static function percentageOf(string $amount, int $percent): string
    {
        $product = bcmul(self::of($amount), (string) $percent, 6);
        $scaled = bcdiv($product, '100', 6);

        return bcadd(bcadd($scaled, '0.005', 6), '0', self::SCALE);
    }
}
