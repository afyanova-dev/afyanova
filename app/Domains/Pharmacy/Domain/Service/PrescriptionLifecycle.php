<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Service;

use App\Domains\Pharmacy\Domain\Exception\IllegalPrescriptionStatusTransition;
use App\Domains\Pharmacy\Domain\ValueObject\PrescriptionStatus;

/**
 * Prescription-record lifecycle rules (16-pharmacy-domain.md §20 / §250;
 * ADR-181 D-D). Pure domain logic, no I/O.
 *
 *   RECEIVED -> ACTIVE      (AcceptPrescription)
 *   RECEIVED | ACTIVE -> CANCELLED  (CancelPrescription — administrative)
 *
 * ACTIVE and CANCELLED are terminal for Pharm.1 (dispensing states arrive with
 * the Pharmacy Order increment).
 */
final class PrescriptionLifecycle
{
    public function assertAcceptable(string $status): void
    {
        if ($status !== PrescriptionStatus::RECEIVED) {
            throw IllegalPrescriptionStatusTransition::fromStatus('AcceptPrescription', $status);
        }
    }

    public function assertCancellable(string $status): void
    {
        if (! in_array($status, [PrescriptionStatus::RECEIVED, PrescriptionStatus::ACTIVE], true)) {
            throw IllegalPrescriptionStatusTransition::fromStatus('CancelPrescription', $status);
        }
    }
}
