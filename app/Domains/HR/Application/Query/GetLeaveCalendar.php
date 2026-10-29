<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\LeaveRequest;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;

#[ProtectedQuery(ability: 'view', subjectType: LeaveRequest::class)]
final readonly class GetLeaveCalendar
{
    public CarbonImmutable $startDate;

    public CarbonImmutable $endDate;

    public function __construct(
        public string $facilityId,
        string|CarbonImmutable $startDate,
        string|CarbonImmutable $endDate,
    ) {
        $this->startDate = is_string($startDate) ? CarbonImmutable::parse($startDate) : $startDate;
        $this->endDate = is_string($endDate) ? CarbonImmutable::parse($endDate) : $endDate;
    }
}
