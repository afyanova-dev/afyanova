<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded reason sets for the Pharmacy Order transitions
 * (16-pharmacy-domain.md §58, §123, §124; ADR-182 D-B / D-D).
 *
 * Open-coded, SET-validated on the write path (the `ClinicalPlanType` /
 * `StockAdjustment` precedent — NOT a PHP enum, NOT #[TypeScript]). New values
 * via a code + an ADR note, not a migration. The free-text detail is the
 * ROW-ONLY `reason_note` column, never in an event or audit metadata.
 */
final class PharmacyOrderReason
{
    /** §124 — Pharmacy Order Rejection. */
    public const REJECT = [
        'INVALID_PRESCRIPTION',
        'EXPIRED_PRESCRIPTION',
        'SAFETY',
        'CLINICAL_CLARIFICATION',
        'DUPLICATE',
        'OTHER',
    ];

    /** §123 — Pharmacy Order ON_HOLD. */
    public const HOLD = [
        'PAYMENT_REQUIRED',
        'INSURANCE_PENDING',
        'STOCK_UNAVAILABLE',
        'CLINICAL_CLARIFICATION',
        'SAFETY_REVIEW',
        'PATIENT_VERIFICATION',
        'OTHER',
    ];

    /** §58 — Dispensing / order cancellation. */
    public const CANCEL = [
        'PATIENT_DECLINED',
        'PRESCRIPTION_CANCELLED',
        'STOCK_UNAVAILABLE',
        'CLINICAL_INSTRUCTION',
        'DUPLICATE',
        'OTHER',
    ];

    public static function assertReject(string $code): string
    {
        return self::assertIn($code, self::REJECT, 'reject');
    }

    public static function assertHold(string $code): string
    {
        return self::assertIn($code, self::HOLD, 'hold');
    }

    public static function assertCancel(string $code): string
    {
        return self::assertIn($code, self::CANCEL, 'cancel');
    }

    /**
     * @param  list<string>  $set
     */
    private static function assertIn(string $code, array $set, string $action): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, $set, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid pharmacy-order %s reason [%s]. Permitted: %s.',
                $action,
                $code,
                implode(', ', $set),
            ));
        }

        return $code;
    }
}
