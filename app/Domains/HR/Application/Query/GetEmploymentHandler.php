<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\EmploymentSummary;
use App\Domains\HR\Domain\EmploymentReader;

final class GetEmploymentHandler
{
    public function __construct(private readonly EmploymentReader $reader) {}

    public function __invoke(GetEmployment $query): EmploymentSummary
    {
        return $this->reader->summary($query->employmentId);
    }
}
