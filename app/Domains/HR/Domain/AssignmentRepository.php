<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\Assignment;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Assignment aggregate root (25-hr-and-workforce-domain.md §3; ADR-206).
 */
interface AssignmentRepository extends Repository
{
    public function getById(string $id): Assignment;

    public function getByIdForUpdate(string $id): Assignment;

    public function getActivePrimaryAssignment(string $staffId): ?Assignment;

    public function existsById(string $id): bool;
}
