<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use InvalidArgumentException;

/**
 * Fixed-scale-2 decimal arithmetic for procurement money amounts (unit prices,
 * line totals, estimated values) — the Inventory `Quantity` precedent. Domain-
 * local (NOT shared-kernel, NOT `#[TypeScript]`). Currency is carried
 * separately on the aggregate (single `TZS` in Proc.1a); this helper is
 * scalar-only.
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

    /**
     * `unit_price * quantity`, at money scale. `$quantity` is a plain decimal
     * string (line quantities are not money).
     *
     * @return numeric-string
     */
    public static function multiplyByQuantity(string $unitPrice, string $quantity): string
    {
        if (! is_numeric($quantity)) {
            throw new InvalidArgumentException("Money::multiplyByQuantity quantity [{$quantity}] is not numeric.");
        }

        return bcmul(self::of($unitPrice), bcadd($quantity, '0', 4), self::SCALE);
    }

    /** @return numeric-string */
    public static function add(string $a, string $b): string
    {
        return bcadd(self::of($a), self::of($b), self::SCALE);
    }

    /** `$a − $b`, at money scale (ADR-173 — quotation line tax/discount). @return numeric-string */
    public static function subtract(string $a, string $b): string
    {
        return bcsub(self::of($a), self::of($b), self::SCALE);
    }

    public static function isNegative(string $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) < 0;
    }

    /** `$value > 0`, at money scale (the single-actor approval-limit guard). */
    public static function isPositive(string $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) > 0;
    }

    /** `$a >= $b`, at money scale (ADR-175 — the §23 "requires evaluation" threshold). */
    public static function gte(string $a, string $b): bool
    {
        return bccomp(self::of($a), self::of($b), self::SCALE) >= 0;
    }
}
