<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\PrescriptionRepository;
use App\Domains\Pharmacy\Domain\Service\PrescriptionLifecycle;

/**
 * Orchestrates `CancelPrescription` (ADR-181 D-E). `getByIdForUpdate` →
 * `assertCancellable` (RECEIVED | ACTIVE) → `->cancel()` (records
 * `PrescriptionCancelled`; `cancel_reason` ROW-ONLY) → save.
 *
 * `PrescriptionRepository` is the first repository parameter → the bus resolves
 * `PrescriptionPolicy::cancel` (per-subject, at the prescription's facility).
 */
final class CancelPrescriptionHandler
{
    public function __construct(
        private readonly PrescriptionRepository $prescriptions,
        private readonly PrescriptionLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelPrescription $command): void
    {
        $prescription = $this->prescriptions->getByIdForUpdate($command->prescriptionId);

        $this->lifecycle->assertCancellable((string) $prescription->status);

        $prescription->cancel($command->actorId, $command->reason);

        $this->prescriptions->save($prescription);
    }
}
