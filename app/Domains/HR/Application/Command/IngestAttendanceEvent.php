<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\AttendanceDirection;
use App\Domains\HR\Domain\ValueObject\AttendanceSource;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class IngestAttendanceEvent implements AuthorizesAgainst
{
    public string $attendanceEventId;

    public AttendanceSource $source;

    public AttendanceDirection $direction;

    public CarbonImmutable $occurredAt;

    /**
     * @param  array<string, mixed>|null  $rawPayload
     */
    public function __construct(
        public string $facilityId,
        public string $staffId,
        AttendanceSource|string $source,
        AttendanceDirection|string $direction,
        string|CarbonImmutable $occurredAt,
        public ?string $deviceRef = null,
        public ?string $externalEventId = null,
        public ?array $rawPayload = null,
        public ?string $notes = null,
        ?string $attendanceEventId = null,
    ) {
        $this->attendanceEventId = $attendanceEventId ?? (string) Str::uuid();
        $this->source = is_string($source) ? AttendanceSource::from($source) : $source;
        $this->direction = is_string($direction) ? AttendanceDirection::from($direction) : $direction;
        $this->occurredAt = is_string($occurredAt) ? CarbonImmutable::parse($occurredAt) : $occurredAt;
    }

    public function policyAbility(): string
    {
        return 'ingest';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
