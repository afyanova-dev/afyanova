<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Repository;

use App\Domains\HR\Domain\Exception\LeaveLedgerEntryNotFoundException;
use App\Domains\HR\Domain\LeaveLedgerEntryRepository;
use App\Domains\HR\Domain\Model\LeaveLedgerEntry;
use App\Shared\Domain\EloquentRepository;

/**
 * Eloquent implementation of LeaveLedgerEntryRepository (25-hr-and-workforce-domain.md §6, §7.9).
 */
final class EloquentLeaveLedgerEntryRepository extends EloquentRepository implements LeaveLedgerEntryRepository
{
    protected function model(): string
    {
        return LeaveLedgerEntry::class;
    }

    public function getById(string $id): LeaveLedgerEntry
    {
        return LeaveLedgerEntry::query()->whereKey($id)->first()
            ?? throw LeaveLedgerEntryNotFoundException::forId($id);
    }

    public function getBalance(string $staffId, string $leaveTypeId): float
    {
        return (float) LeaveLedgerEntry::query()
            ->where('staff_id', $staffId)
            ->where('leave_type_id', $leaveTypeId)
            ->sum('delta');
    }

    public function hasEntriesForType(string $leaveTypeId): bool
    {
        return LeaveLedgerEntry::query()
            ->where('leave_type_id', $leaveTypeId)
            ->exists();
    }
}
