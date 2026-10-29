<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Patient\Contracts\PatientApi;
use App\Domains\Pharmacy\Domain\Exception\TranscriptionContextUnresolved;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\PrescriptionRepository;
use App\Domains\Pharmacy\Infrastructure\Sequence\PrescriptionNumberSequence;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `TranscribePaperPrescription` (Pharm.4).
 *
 * Flow: verify patient registration at the current facility (external paper
 * intake does not create a clinical visit) → `Prescription::transcribe`
 * (RECEIVED + external-paper provenance, NO decision link) → save. The
 * medication snapshot is typed at accept-time (`AcceptTranscription`).
 */
final class TranscribePaperPrescriptionHandler
{
    public function __construct(
        private readonly PrescriptionRepository $prescriptions,
        private readonly PatientApi $patients,
        private readonly PrescriptionNumberSequence $numbers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(TranscribePaperPrescription $command): void
    {
        $facilityId = $this->context->requireFacilityId();
        if ($command->facilityId !== $facilityId
            || ! $this->patients->patientIsRegisteredAtFacility($command->patientId, $facilityId)) {
            throw new TranscriptionContextUnresolved(
                $command->patientId,
                $facilityId,
            );
        }

        $tenantId = $this->context->tenantId();
        $now = CarbonImmutable::now();

        $prescription = Prescription::transcribe(
            id: $command->prescriptionId,
            tenantId: $tenantId,
            facilityId: $command->facilityId,
            patientId: $command->patientId,
            encounterId: null,
            prescriptionNumber: $this->numbers->next($tenantId),
            prescriberName: $command->prescriberName,
            transcribedByUserId: $command->actorId,
            transcriptionNote: $command->transcriptionNote,
            externalIntakeId: $command->externalIntakeId,
            occurredAt: $now,
            recordedAt: $now,
        );

        $this->prescriptions->save($prescription);
    }
}
