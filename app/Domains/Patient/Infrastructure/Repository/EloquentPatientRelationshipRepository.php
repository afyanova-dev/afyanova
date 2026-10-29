<?php

declare(strict_types=1);

namespace App\Domains\Patient\Infrastructure\Repository;

use App\Domains\Patient\Domain\Model\PatientRelationship;
use App\Domains\Patient\Domain\PatientRelationshipRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of {@see PatientRelationshipRepository}
 * (IMPLEMENTATION-CONTRACTS.md §9; ADR-126). `save` (persist + drain events to
 * the outbox) is inherited unchanged — PatientRelationship is a leaf aggregate.
 */
final class EloquentPatientRelationshipRepository extends EloquentRepository implements PatientRelationshipRepository
{
    protected function model(): string
    {
        return PatientRelationship::class;
    }

    public function getById(string $id): PatientRelationship
    {
        return PatientRelationship::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(PatientRelationship::class, $id);
    }

    public function getByIdForUpdate(string $id): PatientRelationship
    {
        return PatientRelationship::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(PatientRelationship::class, $id);
    }
}
