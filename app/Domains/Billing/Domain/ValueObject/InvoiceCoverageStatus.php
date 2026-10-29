<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

/**
 * The coverage status recorded on a `bill_invoices` / `bill_invoice_lines` row
 * (20-billing-and-payments-domain.md §34; ADR-201 D-C). The §33 set —
 * COVERED | PARTIALLY_COVERED | NOT_COVERED | REQUIRES_AUTHORIZATION | UNKNOWN
 * — mirroring the Insurance `CoverageDecision.coverageStatus` string a line
 * stores verbatim. Backend-only string-constant holder.
 */
final class InvoiceCoverageStatus
{
    public const COVERED = 'COVERED';

    public const PARTIALLY_COVERED = 'PARTIALLY_COVERED';

    public const NOT_COVERED = 'NOT_COVERED';

    public const REQUIRES_AUTHORIZATION = 'REQUIRES_AUTHORIZATION';

    public const UNKNOWN = 'UNKNOWN';

    /**
     * The invoice-level rollup of its lines' statuses (ADR-201 D-C): any
     * REQUIRES_AUTHORIZATION dominates; all-identical passes through; anything
     * mixed is PARTIALLY_COVERED.
     *
     * @param  list<string>  $lineStatuses
     */
    public static function rollup(array $lineStatuses): string
    {
        if ($lineStatuses === []) {
            return self::UNKNOWN;
        }
        if (in_array(self::REQUIRES_AUTHORIZATION, $lineStatuses, true)) {
            return self::REQUIRES_AUTHORIZATION;
        }

        $unique = array_values(array_unique($lineStatuses));

        return count($unique) === 1 ? $unique[0] : self::PARTIALLY_COVERED;
    }
}
