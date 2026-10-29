<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\EmploymentRepository;
use App\Domains\HR\Domain\Exception\EmploymentNotFoundException;
use App\Domains\HR\Domain\Model\Employment;
use App\Domains\HR\Domain\ValueObject\EmploymentStatus;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of EmploymentRepository (25-hr-and-workforce-domain.md §3; ADR-206).
 */
final class EloquentEmploymentRepository extends EloquentRepository implements EmploymentRepository
{
    protected function model(): string
    {
        return Employment::class;
    }

    public function getById(string $id): Employment
    {
        return Employment::query()->whereKey($id)->first()
            ?? throw EmploymentNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): Employment
    {
        return Employment::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw EmploymentNotFoundException::forId($id);
    }

    public function getActiveEmploymentForStaff(string $staffId): ?Employment
    {
        return Employment::query()
            ->where('staff_id', $staffId)
            ->where('status', EmploymentStatus::ACTIVE->value)
            ->first();
    }

    public function existsById(string $id): bool
    {
        return Employment::query()->whereKey($id)->exists();
    }
}
