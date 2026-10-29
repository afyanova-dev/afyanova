<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Pharmacy\Domain\Exception\PrescriptionContextMismatch;
use App\Domains\Pharmacy\Domain\PrescriptionRepository;
use App\Domains\Pharmacy\Domain\Service\PrescriptionLifecycle;

/**
 * Orchestrates `AcceptPrescription` (ADR-181 D-E).
 *
 * Flow: `getByIdForUpdate` → `assertAcceptable` (RECEIVED only) → fetch the
 * prescribing decision through the PROTECTED `ClinicalApi::getPrescribingDecision`
 * (delegates to Clinical's `GetPrescribingDecision` `#[ProtectedQuery]` — the
 * acting pharmacist must hold `clinical.prescribing.read` and be scoped to the
 * originating facility; a `NotFound` propagates) → the decision's patient must
 * match the stub (`PrescriptionContextMismatch`) → `->accept()` writes the
 * immutable snapshot + records `PrescriptionAccepted` → save.
 *
 * `PrescriptionRepository` is the first repository parameter → the bus resolves
 * `PrescriptionPolicy::accept` (per-subject, at the prescription's facility).
 */
final class AcceptPrescriptionHandler
{
    public function __construct(
        private readonly PrescriptionRepository $prescriptions,
        private readonly ClinicalApi $clinical,
        private readonly PrescriptionLifecycle $lifecycle,
    ) {}

    public function __invoke(AcceptPrescription $command): void
    {
        $prescription = $this->prescriptions->getByIdForUpdate($command->prescriptionId);

        $this->lifecycle->assertAcceptable((string) $prescription->status);

        $decision = $this->clinical->getPrescribingDecision((string) $prescription->prescribing_decision_id);

        if ($decision->patientId !== (string) $prescription->patient_id) {
            throw PrescriptionContextMismatch::patient(
                (string) $prescription->id,
                (string) $prescription->patient_id,
                $decision->patientId,
            );
        }

        $prescription->accept($decision, $command->actorId);

        $this->prescriptions->save($prescription);
    }
}
