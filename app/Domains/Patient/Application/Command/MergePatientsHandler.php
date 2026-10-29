<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Exception\PatientMergeRejected;
use App\Domains\Patient\Domain\Model\PatientIdentityHistory;
use App\Domains\Patient\Domain\PatientIdentityHistoryRepository;
use App\Domains\Patient\Domain\PatientRepository;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * PatientRepository is listed first so AuthorizeCommand::ownerRepositoryType()
 * (IC §5.1) resolves the subject aggregate as Patient (the bus loads the
 * non-surviving patient for the per-subject `merge` check).
 *
 * ADR-128: identity-layer only. One transaction; both Patient rows locked in
 * deterministic (ascending-id) order; the non-surviving Patient -> MERGED; one
 * ACTIVE identity-history row; PatientMerged on the outbox; handler-side audit.
 */
final class MergePatientsHandler
{
    public function __construct(
        private readonly PatientRepository $patients,
        private readonly PatientIdentityHistoryRepository $identityHistory,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(MergePatients $command): MergePatientsResult
    {
        // Deterministic lock order (ADR-128 M-13) — lock by ascending id so two
        // concurrent merges over an overlapping pair cannot deadlock.
        $ids = [$command->survivingPatientId, $command->nonSurvivingPatientId];
        sort($ids);
        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = $this->patients->getByIdForUpdate($id); // RLS-scoped; NotFound if missing / cross-tenant
        }

        $surviving = $locked[$command->survivingPatientId];
        $nonSurviving = $locked[$command->nonSurvivingPatientId];

        if ($surviving->status !== PatientStatus::Active && $surviving->status !== PatientStatus::Inactive) {
            throw PatientMergeRejected::survivorNotEligible($surviving->status);
        }
        if ($nonSurviving->status !== PatientStatus::Active && $nonSurviving->status !== PatientStatus::Inactive) {
            throw PatientMergeRejected::nonSurvivorNotEligible($nonSurviving->status);
        }

        $mergeId = (string) Str::uuid7();
        $priorStatus = $nonSurviving->status;
        $nonSurvivingNumber = $nonSurviving->patient_number;

        $nonSurviving->merge($command->survivingPatientId, $mergeId); // -> MERGED, records PatientMerged

        $history = PatientIdentityHistory::record(
            id: (string) Str::uuid7(),
            tenantId: $this->context->tenantId(),
            mergeId: $mergeId,
            survivingPatientId: $command->survivingPatientId,
            nonSurvivingPatientId: $command->nonSurvivingPatientId,
            nonSurvivingPatientNumber: $nonSurvivingNumber,
            nonSurvivingPriorStatus: $priorStatus,
            reason: $command->reason,
            evidence: $command->evidence,
            mergedByUserId: $command->actorId,
        );

        $this->patients->save($nonSurviving);        // versioned UPDATE + PatientMerged -> outbox
        $this->identityHistory->save($history);      // INSERT (no events)

        $this->audit->record(
            action: 'patient.merged',
            actorId: $command->actorId,
            targetType: 'patient',
            targetId: $command->nonSurvivingPatientId,
            reason: $command->reason,
            metadata: [
                'merge_id' => $mergeId,
                'surviving_patient_id' => $command->survivingPatientId,
                'non_surviving_patient_id' => $command->nonSurvivingPatientId,
                'non_surviving_patient_number' => $nonSurvivingNumber,
                'non_surviving_prior_status' => $priorStatus->value,
                'evidence' => $command->evidence,
            ],
        );

        return new MergePatientsResult($mergeId, $command->survivingPatientId, $command->nonSurvivingPatientId);
    }
}
