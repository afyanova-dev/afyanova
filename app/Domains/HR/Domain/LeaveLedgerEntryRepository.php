<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\LeaveLedgerEntry;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for LeaveLedgerEntry aggregate root (25-hr-and-workforce-domain.md §6, §7.9).
 */
interface LeaveLedgerEntryRepository extends Repository
{
    public function getById(string $id): LeaveLedgerEntry;

    public function getBalance(string $staffId, string $leaveTypeId): float;

    public function hasEntriesForType(string $leaveTypeId): bool;
}
