<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Exception\PatientMergeRejected;
use App\Domains\Patient\Domain\PatientIdentityHistoryRepository;
use App\Domains\Patient\Domain\PatientRepository;
use App\Shared\Audit\AuditLogger;

/**
 * PatientRepository is listed first so AuthorizeCommand::ownerRepositoryType()
 * (IC §5.1) resolves the subject aggregate as Patient (the bus loads the
 * MERGED patient for the per-subject `merge` check).
 *
 * ADR-128 M-3: immediate identity-layer reversal. One transaction; the patient
 * row locked; status restored to the exact captured pre-merge value; the
 * identity-history row ACTIVE -> REVERSED; PatientUnmerged on the outbox;
 * handler-side audit.
 */
final class RequestUnmergeHandler
{
    public function __construct(
        private readonly PatientRepository $patients,
        private readonly PatientIdentityHistoryRepository $identityHistory,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(RequestUnmerge $command): RequestUnmergeResult
    {
        $patient = $this->patients->getByIdForUpdate($command->nonSurvivingPatientId); // RLS-scoped; NotFound if missing / cross-tenant

        $history = $this->identityHistory->activeForNonSurviving($command->nonSurvivingPatientId)
            ?? throw PatientMergeRejected::notCurrentlyMerged($command->nonSurvivingPatientId);

        $priorStatus = $history->non_surviving_prior_status;

        $patient->restoreFromMerge($priorStatus, (string) $history->surviving_patient_id, (string) $history->merge_id); // MERGED -> prior, records PatientUnmerged
        $history->reverse($command->actorId);                                                                          // ACTIVE -> REVERSED

        $this->patients->save($patient);        // versioned UPDATE + PatientUnmerged -> outbox
        $this->identityHistory->save($history); // versioned UPDATE (no events)

        $this->audit->record(
            action: 'patient.unmerged',
            actorId: $command->actorId,
            targetType: 'patient',
            targetId: $command->nonSurvivingPatientId,
            reason: $command->reason,
            metadata: [
                'merge_id' => (string) $history->merge_id,
                'surviving_patient_id' => (string) $history->surviving_patient_id,
                'non_surviving_patient_id' => $command->nonSurvivingPatientId,
                'restored_status' => $priorStatus->value,
            ],
        );

        return new RequestUnmergeResult((string) $history->merge_id, $command->nonSurvivingPatientId, $priorStatus);
    }
}
