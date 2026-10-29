<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\LeaveBalanceSummary;
use App\Domains\HR\Domain\LeaveLedgerReader;

final class GetLeaveBalanceHandler
{
    public function __construct(
        private readonly LeaveLedgerReader $reader,
    ) {}

    public function __invoke(GetLeaveBalance $query): LeaveBalanceSummary
    {
        return $this->reader->getBalance(
            staffId: $query->staffId,
            leaveTypeId: $query->leaveTypeId,
        );
    }
}
