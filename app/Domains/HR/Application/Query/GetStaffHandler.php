<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\StaffSummary;
use App\Domains\HR\Domain\StaffReader;

final class GetStaffHandler
{
    public function __construct(private readonly StaffReader $reader) {}

    public function __invoke(GetStaff $query): StaffSummary
    {
        return $this->reader->summary($query->staffId);
    }
}
