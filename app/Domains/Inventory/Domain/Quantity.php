<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Domain;

use InvalidArgumentException;

/**
 * Fixed-scale decimal arithmetic for stock quantities
 * (18-inventory-and-warehousing-domain.md §84; ADR-170 D-F / D-I). All stock
 * maths funnels through here so the scale is consistent and every operand is a
 * validated numeric string.
 */
final class Quantity
{
    public const SCALE = 4;

    /**
     * Validate + normalise to a fixed-scale numeric string.
     *
     * @return numeric-string
     */
    public static function of(string $value): string
    {
        if (is_numeric($value)) {
            return bcadd($value, '0', self::SCALE);
        }

        throw new InvalidArgumentException("Quantity [{$value}] is not numeric.");
    }

    /** @return numeric-string */
    public static function add(string $a, string $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    /** @return numeric-string */
    public static function sub(string $a, string $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    /**
     * Multiply to the fixed scale — used by the pack conversion
     * (entered quantity x pack size = base quantity).
     *
     * @return numeric-string
     */
    public static function mul(string $a, string $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    /** -1 if a < b, 0 if equal, 1 if a > b. */
    public static function compare(string $a, string $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function isPositive(string $value): bool
    {
        return self::compare($value, '0') > 0;
    }

    public static function isNegative(string $value): bool
    {
        return self::compare($value, '0') < 0;
    }
}
