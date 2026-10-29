<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\AttendanceSession;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;

#[ProtectedQuery(ability: 'view', subjectType: AttendanceSession::class)]
final readonly class GetAttendanceExceptions
{
    public ?CarbonImmutable $from;

    public ?CarbonImmutable $to;

    public function __construct(
        public string $facilityId,
        string|CarbonImmutable|null $from = null,
        string|CarbonImmutable|null $to = null,
        public ?string $staffId = null,
    ) {
        $this->from = is_string($from) ? CarbonImmutable::parse($from) : $from;
        $this->to = is_string($to) ? CarbonImmutable::parse($to) : $to;
    }
}
