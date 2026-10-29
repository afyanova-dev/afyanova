<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Repository;

use App\Domains\Patient\Domain\Enum\MergeHistoryStatus;
use App\Domains\Patient\Domain\Model\PatientIdentityHistory;
use App\Domains\Patient\Domain\PatientIdentityHistoryRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PatientIdentityHistoryRepository}
 * (IMPLEMENTATION-CONTRACTS.md §9; ADR-128). `save` (versioned persist + drain
 * events to the outbox) is inherited unchanged — this aggregate records no
 * events, so the outbox write is a no-op.
 */
final class EloquentPatientIdentityHistoryRepository extends EloquentRepository implements PatientIdentityHistoryRepository
{
    protected function model(): string
    {
        return PatientIdentityHistory::class;
    }

    public function getById(string $id): PatientIdentityHistory
    {
        return PatientIdentityHistory::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(PatientIdentityHistory::class, $id);
    }

    public function getByIdForUpdate(string $id): PatientIdentityHistory
    {
        return PatientIdentityHistory::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PatientIdentityHistory::class, $id);
    }

    public function activeForNonSurviving(string $nonSurvivingPatientId): ?PatientIdentityHistory
    {
        return PatientIdentityHistory::query()
            ->where('non_surviving_patient_id', $nonSurvivingPatientId)
            ->where('status', MergeHistoryStatus::Active->value)
            ->first();
    }
}
