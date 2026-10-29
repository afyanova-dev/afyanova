<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\AssignmentRepository;
use App\Domains\HR\Domain\Exception\AssignmentNotFoundException;
use App\Domains\HR\Domain\Model\Assignment;
use App\Domains\HR\Domain\ValueObject\AssignmentStatus;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of AssignmentRepository (25-hr-and-workforce-domain.md §3; ADR-206).
 */
final class EloquentAssignmentRepository extends EloquentRepository implements AssignmentRepository
{
    protected function model(): string
    {
        return Assignment::class;
    }

    public function getById(string $id): Assignment
    {
        return Assignment::query()->whereKey($id)->first()
            ?? throw AssignmentNotFoundException::forId($id);
    }

    public function getByIdForUpdate(string $id): Assignment
    {
        return Assignment::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw AssignmentNotFoundException::forId($id);
    }

    public function getActivePrimaryAssignment(string $staffId): ?Assignment
    {
        return Assignment::query()
            ->where('staff_id', $staffId)
            ->where('is_primary', true)
            ->where('status', AssignmentStatus::ACTIVE->value)
            ->first();
    }

    public function existsById(string $id): bool
    {
        return Assignment::query()->whereKey($id)->exists();
    }
}
