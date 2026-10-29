<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\AttendanceSession;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;

#[ProtectedQuery(ability: 'view', subjectType: AttendanceSession::class)]
final readonly class GetTimesheet
{
    public CarbonImmutable $startDate;

    public CarbonImmutable $endDate;

    public function __construct(
        public string $staffId,
        public string $facilityId,
        string|CarbonImmutable $startDate,
        string|CarbonImmutable $endDate,
    ) {
        $this->startDate = is_string($startDate) ? CarbonImmutable::parse($startDate)->startOfDay() : $startDate->startOfDay();
        $this->endDate = is_string($endDate) ? CarbonImmutable::parse($endDate)->endOfDay() : $endDate->endOfDay();
    }
}
