<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle status set for the Prescription record
 * (16-pharmacy-domain.md §20 / §250; ADR-181 D-D — the reconciled §250 subset):
 * RECEIVED | ACTIVE | CANCELLED.
 *
 * RECEIVED — the event-driven stub (IngestPrescription).
 * ACTIVE   — a pharmacist ran AcceptPrescription; the clinical snapshot is set.
 * CANCELLED — administrative terminal state (CancelPrescription).
 *
 * Backend-only string-constant holder (NOT a PHP enum, NOT #[TypeScript] —
 * `generated.ts` stays byte-identical; the `PrescribingDecisionStatus` /
 * `StockAdjustment` precedent). VALIDATING / VALIDATED / REJECTED / READY (§249)
 * are the Pharmacy Order workflow, deferred; EXPIRED (§56 / §57) is deferred.
 */
final class PrescriptionStatus
{
    public const RECEIVED = 'RECEIVED';

    public const ACTIVE = 'ACTIVE';

    public const CANCELLED = 'CANCELLED';

    /** @var list<string> */
    public const ALL = [
        self::RECEIVED,
        self::ACTIVE,
        self::CANCELLED,
    ];

    public static function assert(string $value): string
    {
        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid PrescriptionStatus [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
