<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\LeaveTypeSummary;
use App\Domains\HR\Domain\LeaveTypeReader;

final class GetLeaveTypesHandler
{
    public function __construct(
        private readonly LeaveTypeReader $reader,
    ) {}

    /**
     * @return list<LeaveTypeSummary>
     */
    public function __invoke(GetLeaveTypes $query): array
    {
        return $this->reader->listAll($query->onlyActive);
    }
}
