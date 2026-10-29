<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded category set for a billing service
 * (20-billing-and-payments-domain.md §236 / §237; ADR-188 D-A).
 *
 * Open-coded, SET-validated on the write path (the `PharmacyOrderReason` /
 * `FinancialAccountReason` precedent — NOT a PHP enum, NOT #[TypeScript]).
 */
final class BillingServiceCategory
{
    public const ALL = [
        'CONSULTATION',
        'PROCEDURE',
        'LABORATORY',
        'RADIOLOGY',
        'MEDICATION',
        'BED_DAY',
        'OTHER',
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid billing-service category [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }
}
