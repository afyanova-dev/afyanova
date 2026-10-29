<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Exception\LeaveTypeNotFoundException;
use App\Domains\HR\Domain\LeaveTypeRepository;
use App\Domains\HR\Domain\Model\LeaveType;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of LeaveTypeRepository (25-hr-and-workforce-domain.md §6).
 */
final class EloquentLeaveTypeRepository extends EloquentRepository implements LeaveTypeRepository
{
    protected function model(): string
    {
        return LeaveType::class;
    }

    public function getById(string $id): LeaveType
    {
        return LeaveType::query()->whereKey($id)->first()
            ?? throw LeaveTypeNotFoundException::forId($id);
    }

    public function getByCode(string $code): LeaveType
    {
        return LeaveType::query()->where('code', $code)->first()
            ?? throw LeaveTypeNotFoundException::forCode($code);
    }

    public function existsByCode(string $code): bool
    {
        return LeaveType::query()->where('code', $code)->exists();
    }
}
