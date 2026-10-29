<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\Staff;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Staff aggregate root (25-hr-and-workforce-domain.md §2; ADR-206).
 */
interface StaffRepository extends Repository
{
    public function getById(string $id): Staff;

    public function getByIdForUpdate(string $id): Staff;

    public function getByStaffNumber(string $staffNumber): Staff;

    public function existsById(string $id): bool;

    public function existsByStaffNumber(string $staffNumber): bool;
}
