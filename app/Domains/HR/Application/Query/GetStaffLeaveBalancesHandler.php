<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\LeaveBalanceSummary;
use App\Domains\HR\Domain\LeaveLedgerReader;

final class GetStaffLeaveBalancesHandler
{
    public function __construct(
        private readonly LeaveLedgerReader $reader,
    ) {}

    /**
     * @return list<LeaveBalanceSummary>
     */
    public function __invoke(GetStaffLeaveBalances $query): array
    {
        return $this->reader->getAllBalancesForStaff(
            staffId: $query->staffId,
        );
    }
}
