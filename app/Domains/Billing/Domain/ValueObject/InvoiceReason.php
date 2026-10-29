<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded reason set for voiding an Invoice
 * (20-billing-and-payments-domain.md §136; ADR-190 D-C).
 *
 * Open-coded, SET-validated (the `FinancialAccountReason` / `PickupCode`
 * precedent). New values via a code + an ADR note, not a migration.
 */
final class InvoiceReason
{
    public const VOID = [
        'BILLING_ERROR',
        'DUPLICATE',
        'PATIENT_DISPUTE',
        'CANCELLED_BEFORE_PAYMENT',
        'OTHER',
    ];

    public static function assertVoid(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, self::VOID, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid invoice void reason [%s]. Permitted: %s.',
                $code,
                implode(', ', self::VOID),
            ));
        }

        return $code;
    }
}
