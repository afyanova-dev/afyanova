<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Model;

use App\Domains\Scheduling\Contracts\Event\ScheduleExceptionAdded;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Schedule exception aggregate root (22-scheduling-and-appointments-domain.md §40).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $resource_id
 * @property CarbonImmutable $exception_date
 * @property string|null $start_time
 * @property string|null $end_time
 * @property bool $is_available
 * @property string $reason
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ScheduleException extends AggregateRoot
{
    protected $table = 'sch_schedule_exceptions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'resource_id',
        'exception_date',
        'start_time',
        'end_time',
        'is_available',
        'reason',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'exception_date' => 'immutable_date',
        'is_available' => 'boolean',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $tenantId,
        string $facilityId,
        string $resourceId,
        string|CarbonImmutable $exceptionDate,
        string $reason,
        bool $isAvailable = false,
        ?string $startTime = null,
        ?string $endTime = null,
        ?string $exceptionId = null,
    ): self {
        $id = $exceptionId ?? (string) Str::uuid();
        $date = is_string($exceptionDate) ? CarbonImmutable::parse($exceptionDate) : $exceptionDate;

        $exception = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'resource_id' => $resourceId,
            'exception_date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'is_available' => $isAvailable,
            'reason' => trim($reason),
            'version' => 0,
        ]);

        $exception->id = $id;

        $exception->recordThat(new ScheduleExceptionAdded(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            exceptionId: $id,
            facilityId: $facilityId,
            resourceId: $resourceId,
            exceptionDate: $date->toDateString(),
            startTime: $startTime,
            endTime: $endTime,
            isAvailable: $isAvailable,
            reason: $exception->reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $exception;
    }
}
