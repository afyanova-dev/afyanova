<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\LeaveType;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for LeaveType aggregate root (25-hr-and-workforce-domain.md §6).
 */
interface LeaveTypeRepository extends Repository
{
    public function getById(string $id): LeaveType;

    public function getByCode(string $code): LeaveType;

    public function existsByCode(string $code): bool;
}
