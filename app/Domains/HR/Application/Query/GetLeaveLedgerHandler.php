<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\LeaveLedgerEntrySummary;
use App\Domains\HR\Domain\LeaveLedgerReader;

final class GetLeaveLedgerHandler
{
    public function __construct(
        private readonly LeaveLedgerReader $reader,
    ) {}

    /**
     * @return list<LeaveLedgerEntrySummary>
     */
    public function __invoke(GetLeaveLedger $query): array
    {
        return $this->reader->forStaff(
            staffId: $query->staffId,
            leaveTypeId: $query->leaveTypeId,
            from: $query->from,
            to: $query->to,
        );
    }
}
