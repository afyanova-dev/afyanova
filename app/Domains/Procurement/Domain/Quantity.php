<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use InvalidArgumentException;

/**
 * Fixed-scale-4 decimal arithmetic for procurement line quantities — the
 * `pcr_po_lines` / `pcr_requisition_lines` / `pcr_rfq_lines` /
 * `pcr_quotation_lines` `decimal(18,4)` columns, the `received_qty`
 * accumulation (ADR-172 D-P), and the RFQ award allocation (ADR-173 D-D).
 * Mirrors {@see Money} exactly, at scale 4. Domain-local (NOT shared-kernel, NOT
 * `#[TypeScript]`); Inventory's own `Quantity` is Inventory's — a cross-domain
 * import is forbidden.
 */
final class Quantity
{
    public const SCALE = 4;

    /**
     * Validate + normalise to a fixed-scale-4 numeric string.
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

    /** `$a − $b` (clamped at 0 — an outstanding RFQ-line qty is never negative). @return numeric-string */
    public static function subtract(string $a, string $b): string
    {
        $result = bcsub(self::of($a), self::of($b), self::SCALE);

        return bccomp($result, '0', self::SCALE) < 0 ? bcadd('0', '0', self::SCALE) : $result;
    }

    /**
     * Multiply to the fixed scale — the pack conversion
     * (entered quantity x pack size = base quantity).
     *
     * @return numeric-string
     */
    public static function multiply(string $a, string $b): string
    {
        return bcmul(self::of($a), self::of($b), self::SCALE);
    }

    /** Is `$a` greater than or equal to `$b`? */
    public static function gte(string $a, string $b): bool
    {
        return bccomp(self::of($a), self::of($b), self::SCALE) >= 0;
    }

    public static function isPositive(string $value): bool
    {
        return bccomp(self::of($value), '0', self::SCALE) > 0;
    }
}
