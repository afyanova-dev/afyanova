<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\LeaveBalanceSummary;
use App\Domains\HR\Contracts\Dto\LeaveLedgerEntrySummary;
use Carbon\CarbonImmutable;

/**
 * Read-side port for LeaveLedger queries (25-hr-and-workforce-domain.md §6, §7.9, §10).
 */
interface LeaveLedgerReader
{
    /**
     * @return list<LeaveLedgerEntrySummary>
     */
    public function forStaff(
        string $staffId,
        ?string $leaveTypeId = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array;

    public function getBalance(string $staffId, string $leaveTypeId): LeaveBalanceSummary;

    /**
     * @return list<LeaveBalanceSummary>
     */
    public function getAllBalancesForStaff(string $staffId): array;
}
