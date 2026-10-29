<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Repository;

use App\Domains\Patient\Domain\Model\PatientContact;
use App\Domains\Patient\Domain\PatientContactRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PatientContactRepository}
 * (IMPLEMENTATION-CONTRACTS.md §9; ADR-126). `save` (persist + drain events to
 * the outbox) is inherited unchanged — PatientContact is a leaf aggregate.
 */
final class EloquentPatientContactRepository extends EloquentRepository implements PatientContactRepository
{
    protected function model(): string
    {
        return PatientContact::class;
    }

    public function getById(string $id): PatientContact
    {
        return $this->findById($id)
            ?? throw NotFound::aggregate(PatientContact::class, $id);
    }

    public function getByIdForUpdate(string $id): PatientContact
    {
        return PatientContact::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PatientContact::class, $id);
    }

    public function findById(string $id): ?PatientContact
    {
        return PatientContact::query()->whereKey($id)->first();
    }
}
