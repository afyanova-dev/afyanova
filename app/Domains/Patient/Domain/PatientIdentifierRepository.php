<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the PatientIdentifier aggregate
 * (IMPLEMENTATION-CONTRACTS.md §9). Implementation:
 * Infrastructure/Repository/EloquentPatientIdentifierRepository, bound in
 * PatientServiceProvider. Its FQCN sits directly under Domain/ so
 * App\Shared\Bus\Middleware\AuthorizeCommand::aggregateClass() (IC §5.1) can
 * derive App\Domains\Patient\Domain\Model\PatientIdentifier from it.
 *
 * The return types narrow the base `Repository` (which returns `AggregateRoot`)
 * to `PatientIdentifier` — a covariant refinement so handlers can call its
 * methods without a cast.
 */
interface PatientIdentifierRepository extends Repository
{
    public function getById(string $id): PatientIdentifier;

    public function getByIdForUpdate(string $id): PatientIdentifier;
}
