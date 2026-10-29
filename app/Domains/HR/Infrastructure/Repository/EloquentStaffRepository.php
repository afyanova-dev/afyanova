<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Exception\StaffNotFoundException;
use App\Domains\HR\Domain\Model\Staff;
use App\Domains\HR\Domain\StaffRepository;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of StaffRepository (25-hr-and-workforce-domain.md §2; ADR-206).
 */
final class EloquentStaffRepository extends EloquentRepository implements StaffRepository
{
    protected function model(): string
    {
        return Staff::class;
    }

    public function getById(string $id): Staff
    {
        return Staff::query()->whereKey($id)->first()
            ?? throw StaffNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): Staff
    {
        return Staff::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw StaffNotFoundException::forId($id);
    }

    public function getByStaffNumber(string $staffNumber): Staff
    {
        return Staff::query()->where('staff_number', $staffNumber)->first()
            ?? throw StaffNotFoundException::forNumber($staffNumber);
    }

    public function existsById(string $id): bool
    {
        return Staff::query()->whereKey($id)->exists();
    }

    public function existsByStaffNumber(string $staffNumber): bool
    {
        return Staff::query()->where('staff_number', $staffNumber)->exists();
    }
}
