<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Infrastructure\Reader;

use App\Domains\Theatre\Contracts\Dto\RecoveryHandoffSummary;
use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Domains\Theatre\Domain\RecoveryHandoffReader;
use App\Shared\Domain\NotFound;

final class EloquentRecoveryHandoffReader implements RecoveryHandoffReader
{
    public function forCase(string $caseId): ?RecoveryHandoffSummary
    {
        $row = RecoveryHandoff::query()->where('case_id', $caseId)->first();

        return $row !== null ? $this->toSummary($row) : null;
    }

    public function summary(string $recoveryHandoffId): RecoveryHandoffSummary
    {
        $row = RecoveryHandoff::query()->whereKey($recoveryHandoffId)->first()
            ?? throw NotFound::aggregate(RecoveryHandoff::class, $recoveryHandoffId);

        return $this->toSummary($row);
    }

    private function toSummary(RecoveryHandoff $row): RecoveryHandoffSummary
    {
        return new RecoveryHandoffSummary(
            recoveryHandoffId: (string) $row->id,
            facilityId: (string) $row->facility_id,
            caseId: (string) $row->case_id,
            patientId: (string) $row->patient_id,
            encounterId: (string) $row->encounter_id,
            destinationType: (string) $row->destination_type,
            patientCondition: $row->patient_condition,
            pendingActions: $row->pending_actions,
            receivingTeamRef: $row->receiving_team_ref,
            status: (string) $row->status,
            acknowledgedByUserId: $row->acknowledged_by_user_id,
            acknowledgedAt: $row->acknowledged_at?->toISOString(),
            initiatedByUserId: (string) $row->initiated_by_user_id,
            createdAt: $row->created_at?->toISOString(),
            updatedAt: $row->updated_at?->toISOString(),
        );
    }
}
