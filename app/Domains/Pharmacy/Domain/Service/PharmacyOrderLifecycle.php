<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Service;

use App\Domains\Pharmacy\Domain\Exception\IllegalPharmacyOrderStatusTransition;
use App\Domains\Pharmacy\Domain\Exception\PharmacyOrderNotReadyForDispensing;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;

/**
 * Pharmacy Order lifecycle rules (16-pharmacy-domain.md §122 / §249;
 * ADR-182 D-C). Pure domain logic, no I/O.
 *
 *   RECEIVED  -> VALIDATED   (ValidatePharmacyOrder)
 *   RECEIVED | VALIDATED -> REJECTED  (RejectPharmacyOrder — terminal)
 *   RECEIVED | VALIDATED -> ON_HOLD   (HoldPharmacyOrder)
 *   ON_HOLD -> RECEIVED               (ReleasePharmacyOrderHold — re-validate)
 *   RECEIVED | VALIDATED | ON_HOLD -> CANCELLED  (CancelPharmacyOrder — terminal)
 *   VALIDATED | PARTIALLY_DISPENSED -> PARTIALLY_DISPENSED | DISPENSED
 *       (Dispense — the fulfilment rollup; ADR-184 D-C)
 */
final class PharmacyOrderLifecycle
{
    public function assertValidatable(string $status): void
    {
        $this->assert('ValidatePharmacyOrder', $status, [PharmacyOrderStatus::RECEIVED]);
    }

    public function assertCanDispense(string $pharmacyOrderId, string $status): void
    {
        if (! in_array($status, PharmacyOrderStatus::DISPENSABLE, true)) {
            throw PharmacyOrderNotReadyForDispensing::status($pharmacyOrderId, $status);
        }
    }

    /** A reversal acts on a dispensing whose order is PARTIALLY_DISPENSED | DISPENSED (ADR-185 D-E). */
    public function assertCanReverse(string $pharmacyOrderId, string $status): void
    {
        if (! in_array($status, [PharmacyOrderStatus::PARTIALLY_DISPENSED, PharmacyOrderStatus::DISPENSED], true)) {
            throw PharmacyOrderNotReadyForDispensing::status($pharmacyOrderId, $status);
        }
    }

    public function assertRejectable(string $status): void
    {
        $this->assert('RejectPharmacyOrder', $status, [
            PharmacyOrderStatus::RECEIVED,
            PharmacyOrderStatus::VALIDATED,
        ]);
    }

    public function assertHoldable(string $status): void
    {
        $this->assert('HoldPharmacyOrder', $status, [
            PharmacyOrderStatus::RECEIVED,
            PharmacyOrderStatus::VALIDATED,
        ]);
    }

    public function assertReleasable(string $status): void
    {
        $this->assert('ReleasePharmacyOrderHold', $status, [PharmacyOrderStatus::ON_HOLD]);
    }

    public function assertCancellable(string $status): void
    {
        $this->assert('CancelPharmacyOrder', $status, [
            PharmacyOrderStatus::RECEIVED,
            PharmacyOrderStatus::VALIDATED,
            PharmacyOrderStatus::ON_HOLD,
        ]);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assert(string $action, string $status, array $allowed): void
    {
        if (! in_array($status, $allowed, true)) {
            throw IllegalPharmacyOrderStatusTransition::fromStatus($action, $status);
        }
    }
}
