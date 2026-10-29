<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain;

use App\Domains\Patient\Domain\Model\PatientIdentityHistory;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the PatientIdentityHistory aggregate
 * (IMPLEMENTATION-CONTRACTS.md §9; ADR-128). Implementation:
 * Infrastructure/Repository/EloquentPatientIdentityHistoryRepository, bound in
 * PatientServiceProvider.
 *
 * `activeForNonSurviving` is the lookup RequestUnmerge needs — the single
 * `ACTIVE` row (if any) that currently maps this non-surviving patient to a
 * survivor.
 */
interface PatientIdentityHistoryRepository extends Repository
{
    public function getById(string $id): PatientIdentityHistory;

    public function getByIdForUpdate(string $id): PatientIdentityHistory;

    public function activeForNonSurviving(string $nonSurvivingPatientId): ?PatientIdentityHistory;
}
