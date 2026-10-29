<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded reason set for closing a Financial Account
 * (20-billing-and-payments-domain.md §107, §301; ADR-187 D-D).
 *
 * Open-coded, SET-validated on the write path (the `PharmacyOrderReason` /
 * `PickupCode` precedent — NOT a PHP enum, NOT #[TypeScript]). New values via a
 * code + an ADR note, not a migration.
 */
final class FinancialAccountReason
{
    /** §107 — Financial Account closure. */
    public const CLOSE = [
        'PATIENT_DECEASED',
        'DUPLICATE_ACCOUNT',
        'ACCOUNT_MERGED',
        'ADMIN_CLOSURE',
        'OTHER',
    ];

    public static function assertClose(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, self::CLOSE, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid financial-account close reason [%s]. Permitted: %s.',
                $code,
                implode(', ', self::CLOSE),
            ));
        }

        return $code;
    }
}
