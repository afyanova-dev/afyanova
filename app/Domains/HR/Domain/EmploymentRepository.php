<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\Employment;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Employment aggregate root (25-hr-and-workforce-domain.md §3; ADR-206).
 */
interface EmploymentRepository extends Repository
{
    public function getById(string $id): Employment;

    public function getByIdForUpdate(string $id): Employment;

    public function getActiveEmploymentForStaff(string $staffId): ?Employment;

    public function existsById(string $id): bool;
}
