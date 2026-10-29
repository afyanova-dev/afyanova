<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\ShiftPattern;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for ShiftPattern aggregate root (25-hr-and-workforce-domain.md §6; ADR-206).
 */
interface ShiftPatternRepository extends Repository
{
    public function getById(string $id): ShiftPattern;

    public function getByIdForUpdate(string $id): ShiftPattern;

    public function findByFacilityAndCode(string $facilityId, string $code): ?ShiftPattern;

    public function existsById(string $id): bool;
}
