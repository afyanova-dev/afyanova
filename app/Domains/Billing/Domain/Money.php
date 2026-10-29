<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use InvalidArgumentException;

/**
 * Fixed-scale-2 decimal arithmetic for Billing money amounts (ledger entry
 * amounts, balances) — an exact copy of `App\Domains\Procurement\Domain\Money`
 * (the `Inventory\Domain\Quantity` house precedent: each domain carries its own
 * scalar helper). Domain-local, NOT shared-kernel, NOT `#[TypeScript]`. Currency
 * is carried separately on the row; this helper is scalar-only.
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
    public static function add(string $a, string $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    /**
     * `$a − $b`, at money scale.
     *
     * @return numeric-string
     */
    public static function subtract(string $a, string $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function isNegative(string $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) < 0;
    }

    /** The settle guard — a zero outstanding balance (§84 / ADR-187 D-D). */
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
     * `unitPrice × quantity`, rounded HALF-UP to scale-2 (ADR-189 INV-3 / C5,
     * frozen). Both operands are non-negative in Billing (a positive tariff
     * amount × a positive dispensed quantity), so half-up is
     * add-0.005-then-truncate. `quantity` is a plain decimal string (line
     * quantities are not money).
     *
     * @return numeric-string
     */
    public static function multiply(string $unitPrice, string $quantity): string
    {
        if (! is_numeric($quantity)) {
            throw new InvalidArgumentException("Money::multiply quantity [{$quantity}] is not numeric.");
        }

        $product = bcmul(self::of($unitPrice), $quantity, 6);

        return bcadd(bcadd($product, '0.005', 6), '0', self::SCALE);
    }
}
