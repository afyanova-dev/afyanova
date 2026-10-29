<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\AttendanceEvent;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;

#[ProtectedQuery(ability: 'view', subjectType: AttendanceEvent::class)]
final readonly class GetAttendanceEvents
{
    public ?CarbonImmutable $from;

    public ?CarbonImmutable $to;

    public function __construct(
        public ?string $staffId = null,
        public ?string $facilityId = null,
        string|CarbonImmutable|null $from = null,
        string|CarbonImmutable|null $to = null,
        public ?int $limit = null,
    ) {
        $this->from = is_string($from) ? CarbonImmutable::parse($from) : $from;
        $this->to = is_string($to) ? CarbonImmutable::parse($to) : $to;
    }
}
