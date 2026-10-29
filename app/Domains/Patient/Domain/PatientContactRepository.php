<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Domain\Model\PatientContact;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the PatientContact aggregate
 * (IMPLEMENTATION-CONTRACTS.md §9; ADR-126). Implementation:
 * Infrastructure/Repository/EloquentPatientContactRepository, bound in
 * PatientServiceProvider. Its FQCN sits directly under Domain/ so
 * App\Shared\Bus\Middleware\AuthorizeCommand::aggregateClass() (IC §5.1) can
 * derive App\Domains\Patient\Domain\Model\PatientContact from it.
 *
 * `findById` is the nullable lookup the UpdatePatientContact upsert needs to
 * decide create vs revise — `getById` throws, which the upsert must not.
 */
interface PatientContactRepository extends Repository
{
    public function getById(string $id): PatientContact;

    public function getByIdForUpdate(string $id): PatientContact;

    public function findById(string $id): ?PatientContact;
}
