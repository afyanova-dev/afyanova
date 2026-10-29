<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded reason set for a dispensing reversal
 * (16-pharmacy-domain.md §59, §60, §110; ADR-185 D-B).
 *
 * Open-coded, SET-validated on the write path (the `PharmacyOrderReason` /
 * `ClinicalPlanType` precedent — NOT a PHP enum, NOT #[TypeScript]). New values
 * via a code + an ADR note, not a migration. The free-text detail is the
 * ROW-ONLY `reason_note` column, never in an event or audit metadata.
 */
final class DispensingReversalReason
{
    /** @var list<string> */
    public const ALL = [
        'DISPENSING_ERROR',
        'WRONG_ITEM',
        'WRONG_DOSE',
        'WRONG_PATIENT',
        'NOT_COLLECTED',
        'OTHER',
    ];

    public static function assert(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid dispensing-reversal reason [%s]. Permitted: %s.',
                $code,
                implode(', ', self::ALL),
            ));
        }

        return $code;
    }
}
