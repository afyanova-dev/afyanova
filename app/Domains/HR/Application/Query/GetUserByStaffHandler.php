<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\StaffUserLinkSummary;
use App\Domains\HR\Domain\StaffUserLinkReader;

final class GetUserByStaffHandler
{
    public function __construct(private readonly StaffUserLinkReader $reader) {}

    public function __invoke(GetUserByStaff $query): ?StaffUserLinkSummary
    {
        return $this->reader->findByStaff($query->staffId);
    }
}
