<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Model;

use App\Domains\Scheduling\Contracts\Event\ScheduleDefined;
use App\Domains\Scheduling\Domain\Exception\InvalidScheduleException;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Recurring working schedule aggregate root (22-scheduling-and-appointments-domain.md §37, §39).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $resource_id
 * @property string|null $appointment_type_id
 * @property int $day_of_week
 * @property string $start_time
 * @property string $end_time
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property bool $is_active
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Schedule extends AggregateRoot
{
    protected $table = 'sch_schedules';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'resource_id',
        'appointment_type_id',
        'day_of_week',
        'start_time',
        'end_time',
        'effective_from',
        'effective_to',
        'is_active',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'day_of_week' => 'integer',
        'effective_from' => 'immutable_date',
        'effective_to' => 'immutable_date',
        'is_active' => 'boolean',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function define(
        string $tenantId,
        string $facilityId,
        string $resourceId,
        int $dayOfWeek,
        string $startTime,
        string $endTime,
        string|CarbonImmutable $effectiveFrom,
        string|CarbonImmutable|null $effectiveTo = null,
        ?string $appointmentTypeId = null,
        bool $isActive = true,
        ?string $scheduleId = null,
    ): self {
        if ($startTime >= $endTime) {
            throw InvalidScheduleException::invalidTimes($startTime, $endTime);
        }

        $from = is_string($effectiveFrom) ? CarbonImmutable::parse($effectiveFrom) : $effectiveFrom;
        $to = is_string($effectiveTo) ? CarbonImmutable::parse($effectiveTo) : $effectiveTo;

        if ($to !== null && $from->isAfter($to)) {
            throw InvalidScheduleException::invalidDateRange($from->toDateString(), $to->toDateString());
        }

        $id = $scheduleId ?? (string) Str::uuid();

        $schedule = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'resource_id' => $resourceId,
            'appointment_type_id' => $appointmentTypeId,
            'day_of_week' => $dayOfWeek,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'effective_from' => $from,
            'effective_to' => $to,
            'is_active' => $isActive,
            'version' => 0,
        ]);

        $schedule->id = $id;

        $schedule->recordThat(new ScheduleDefined(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            scheduleId: $id,
            facilityId: $facilityId,
            resourceId: $resourceId,
            appointmentTypeId: $appointmentTypeId,
            dayOfWeek: $dayOfWeek,
            startTime: $startTime,
            endTime: $endTime,
            effectiveFrom: $from->toDateString(),
            effectiveTo: $to?->toDateString(),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $schedule;
    }
}
