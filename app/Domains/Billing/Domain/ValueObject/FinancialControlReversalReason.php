<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded reason set for reversing an APPLIED write-off / adjustment
 * (20-billing-and-payments-domain.md §72 / §134; ADR-191 D7-B). A reversal
 * undoes the accounting effect (a compensating ledger DEBIT); it is itself an
 * SoD-approved action. Open-coded, SET-validated.
 */
final class FinancialControlReversalReason
{
    public const REASONS = [
        'RAISED_IN_ERROR',
        'WRONG_ACCOUNT',
        'AMOUNT_INCORRECT',
        'SUBSEQUENTLY_COLLECTED',
        'OTHER',
    ];

    public static function assert(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid reversal reason [%s]. Permitted: %s (§72 / §134).',
                $code,
                implode(', ', self::REASONS),
            ));
        }

        return $code;
    }
}
