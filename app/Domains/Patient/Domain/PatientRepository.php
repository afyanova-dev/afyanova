<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Patient aggregate (IMPLEMENTATION-CONTRACTS.md §9).
 * Implementation: Infrastructure/Repository/EloquentPatientRepository, bound
 * in PatientServiceProvider. Its FQCN sits directly under Domain/ (not
 * Domain/Repository/) so App\Shared\Bus\Middleware\AuthorizeCommand::
 * aggregateClass() (IC §5.1) can derive App\Domains\Patient\Domain\Model\Patient
 * from it.
 *
 * The return types narrow the base `Repository` (which returns `AggregateRoot`)
 * to `Patient` — a covariant refinement so handlers can call Patient methods
 * without a cast.
 */
interface PatientRepository extends Repository
{
    public function getById(string $id): Patient;

    public function getByIdForUpdate(string $id): Patient;
}
