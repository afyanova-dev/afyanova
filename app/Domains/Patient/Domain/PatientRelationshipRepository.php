<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Domain\Model\PatientRelationship;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the PatientRelationship aggregate
 * (IMPLEMENTATION-CONTRACTS.md §9; ADR-126). Implementation:
 * Infrastructure/Repository/EloquentPatientRelationshipRepository, bound in
 * PatientServiceProvider. Its FQCN sits directly under Domain/ so
 * App\Shared\Bus\Middleware\AuthorizeCommand::aggregateClass() (IC §5.1) can
 * derive App\Domains\Patient\Domain\Model\PatientRelationship from it.
 *
 * Return types narrow the base `Repository` (which returns `AggregateRoot`) to
 * `PatientRelationship` — a covariant refinement.
 */
interface PatientRelationshipRepository extends Repository
{
    public function getById(string $id): PatientRelationship;

    public function getByIdForUpdate(string $id): PatientRelationship;
}
