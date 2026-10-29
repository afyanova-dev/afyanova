<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Repository;

use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\PatientRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PatientRepository} (IMPLEMENTATION-CONTRACTS.md
 * §9). `save` (persist + drain events to the outbox) is inherited from
 * EloquentRepository unchanged — this slice still has no child entities to
 * persist alongside the root. getById / getByIdForUpdate are overridden only
 * to narrow the return type to Patient (the base returns AggregateRoot).
 */
final class EloquentPatientRepository extends EloquentRepository implements PatientRepository
{
    protected function model(): string
    {
        return Patient::class;
    }

    public function getById(string $id): Patient
    {
        return Patient::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(Patient::class, $id);
    }

    public function getByIdForUpdate(string $id): Patient
    {
        return Patient::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(Patient::class, $id);
    }
}
