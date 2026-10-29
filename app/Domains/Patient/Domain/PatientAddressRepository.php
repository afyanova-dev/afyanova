<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Domain\Model\PatientAddress;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the PatientAddress aggregate
 * (IMPLEMENTATION-CONTRACTS.md §9; ADR-126). Implementation:
 * Infrastructure/Repository/EloquentPatientAddressRepository. Its FQCN sits
 * directly under Domain/ so AuthorizeCommand::aggregateClass() (IC §5.1) can
 * derive App\Domains\Patient\Domain\Model\PatientAddress from it.
 *
 * `findById` is the nullable lookup the UpdatePatientAddress upsert needs to
 * decide create vs revise.
 */
interface PatientAddressRepository extends Repository
{
    public function getById(string $id): PatientAddress;

    public function getByIdForUpdate(string $id): PatientAddress;

    public function findById(string $id): ?PatientAddress;
}
