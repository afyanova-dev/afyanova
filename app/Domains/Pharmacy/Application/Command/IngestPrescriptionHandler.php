<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\PrescriptionRepository;
use App\Domains\Pharmacy\Infrastructure\Sequence\PrescriptionNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates `IngestPrescription` (ADR-181 D-E — the C1 split).
 *
 * Flow: idempotency check (a `rx_prescriptions` row already exists for this
 * `prescribing_decision_id` → no-op) → unprotected `patientExists` /
 * `encounterExists` sanity checks (unmatched → log + return, the
 * `RecordPurchaseOrderReceipt` discipline) → allocate `RX-%08d` → write the
 * `RECEIVED` stub from the event payload only. NO clinical content is fetched
 * here — that is the authenticated `AcceptPrescription`.
 *
 * `PrescriptionRepository` is the first parameter so the bus can resolve the
 * policy — but `IngestPrescription` is not `AuthorizesAgainst`, so the check is
 * skipped (system command).
 */
final class IngestPrescriptionHandler
{
    public function __construct(
        private readonly PrescriptionRepository $prescriptions,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly PrescriptionNumberSequence $numbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(IngestPrescription $command): void
    {
        if ($this->prescriptions->findByPrescribingDecisionId($command->prescribingDecisionId) !== null) {
            return; // already ingested (a re-delivered event) — idempotent
        }

        if (! $this->patients->patientExists($command->patientId)
            || ! $this->encounters->encounterExists($command->encounterId)) {
            Log::warning('pharmacy.prescription.ingest_unresolved_context', [
                'prescribing_decision_id' => $command->prescribingDecisionId,
                'patient_id' => $command->patientId,
                'encounter_id' => $command->encounterId,
            ]);

            return;
        }

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();

        $prescription = Prescription::ingest(
            id: $command->prescriptionId,
            tenantId: $tenantId,
            facilityId: $command->facilityId,
            prescribingDecisionId: $command->prescribingDecisionId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            prescriptionNumber: $this->numbers->next($tenantId),
            authorUserId: $command->authorUserId,
            changeType: $command->changeType,
            medicationCodeSystem: $command->medicationCodeSystem,
            route: $command->route,
            urgency: $command->urgency,
            occurredAt: CarbonImmutable::parse($command->occurredAt),
            recordedAt: $now,
        );

        $this->prescriptions->save($prescription);
    }
}
