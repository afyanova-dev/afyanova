<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\EmploymentSummary;
use App\Domains\HR\Domain\EmploymentReader;

final class GetStaffEmploymentsHandler
{
    public function __construct(private readonly EmploymentReader $reader) {}

    /**
     * @return list<EmploymentSummary>
     */
    public function __invoke(GetStaffEmployments $query): array
    {
        return $this->reader->forStaff($query->staffId);
    }
}
