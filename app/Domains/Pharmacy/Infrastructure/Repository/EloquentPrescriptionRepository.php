<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Infrastructure\Repository;

use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\PrescriptionRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PrescriptionRepository} (IC §9; ADR-181 D-D).
 */
final class EloquentPrescriptionRepository extends EloquentRepository implements PrescriptionRepository
{
    protected function model(): string
    {
        return Prescription::class;
    }

    public function getById(string $id): Prescription
    {
        return Prescription::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Prescription::class, $id);
    }

    public function getByIdForUpdate(string $id): Prescription
    {
        return Prescription::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Prescription::class, $id);
    }

    public function findByPrescribingDecisionId(string $prescribingDecisionId): ?Prescription
    {
        return Prescription::query()
            ->where('prescribing_decision_id', $prescribingDecisionId)
            ->first();
    }
}
