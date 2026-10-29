<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Repository;

use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Domains\Patient\Domain\PatientIdentifierRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PatientIdentifierRepository}
 * (IMPLEMENTATION-CONTRACTS.md §9). `save` (persist + drain events to the
 * outbox) is inherited unchanged — PatientIdentifier is a leaf aggregate with
 * no child entities. getById / getByIdForUpdate narrow the return type.
 */
final class EloquentPatientIdentifierRepository extends EloquentRepository implements PatientIdentifierRepository
{
    protected function model(): string
    {
        return PatientIdentifier::class;
    }

    public function getById(string $id): PatientIdentifier
    {
        return PatientIdentifier::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(PatientIdentifier::class, $id);
    }

    public function getByIdForUpdate(string $id): PatientIdentifier
    {
        return PatientIdentifier::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PatientIdentifier::class, $id);
    }
}
