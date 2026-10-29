<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded sets for a Medication Pickup (16-pharmacy-domain.md §29, §30;
 * ADR-186 D-B / D-D).
 *
 * Open-coded, SET-validated on the write path (the `PharmacyOrderReason` /
 * `DispensingReversalReason` precedent — NOT a PHP enum, NOT #[TypeScript]).
 * `RECIPIENT` — §30 (who collected). `OUTCOME` — the NOT_COLLECTED / CANCELLED
 * reason ('DISPENSING_REVERSED' is set by `ReverseDispensingHandler`).
 */
final class PickupCode
{
    /** §30 — Recipient. */
    public const RECIPIENT = [
        'PATIENT',
        'GUARDIAN',
        'AUTHORIZED_REP',
        'WARD',
        'CLINICAL_UNIT',
    ];

    /** The NOT_COLLECTED / CANCELLED reason. */
    public const OUTCOME = [
        'PATIENT_NO_SHOW',
        'PATIENT_DECLINED',
        'PRESCRIPTION_CANCELLED',
        'DUPLICATE',
        'DISPENSING_REVERSED',
        'OTHER',
    ];

    public static function assertRecipient(string $code): string
    {
        return self::assertIn($code, self::RECIPIENT, 'recipient');
    }

    public static function assertOutcome(string $code): string
    {
        return self::assertIn($code, self::OUTCOME, 'outcome');
    }

    /**
     * @param  list<string>  $set
     */
    private static function assertIn(string $code, array $set, string $kind): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, $set, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid pickup %s code [%s]. Permitted: %s.',
                $kind,
                $code,
                implode(', ', $set),
            ));
        }

        return $code;
    }
}
