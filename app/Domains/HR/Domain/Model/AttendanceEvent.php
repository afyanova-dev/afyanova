<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\AttendanceEventIngested;
use App\Domains\HR\Domain\ValueObject\AttendanceDirection;
use App\Domains\HR\Domain\ValueObject\AttendanceSource;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Append-only attendance clocking event (25-hr-and-workforce-domain.md §6, §7.11).
 *
 * Authoritative, immutable record of biometric, mobile, manual, or system clocking events.
 *
 * @domain      HR
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $staff_id
 * @property AttendanceSource $source
 * @property string|null $device_ref
 * @property string|null $external_event_id
 * @property AttendanceDirection $direction
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $ingested_at
 * @property array<string, mixed>|null $raw_payload
 * @property string|null $notes
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property Staff|null $staff
 */
final class AttendanceEvent extends AggregateRoot
{
    protected $table = 'hr_attendance_events';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'staff_id',
        'source',
        'device_ref',
        'external_event_id',
        'direction',
        'occurred_at',
        'ingested_at',
        'raw_payload',
        'notes',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'source' => AttendanceSource::class,
        'direction' => AttendanceDirection::class,
        'occurred_at' => 'immutable_datetime',
        'ingested_at' => 'immutable_datetime',
        'raw_payload' => 'array',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    /**
     * @param  array<string, mixed>|null  $rawPayload
     */
    public static function ingest(
        string $tenantId,
        string $facilityId,
        string $staffId,
        AttendanceSource $source,
        AttendanceDirection $direction,
        CarbonImmutable $occurredAt,
        ?string $deviceRef = null,
        ?string $externalEventId = null,
        ?array $rawPayload = null,
        ?string $notes = null,
        ?CarbonImmutable $ingestedAt = null,
        ?string $attendanceEventId = null,
    ): self {
        $id = $attendanceEventId ?? (string) Str::uuid();
        $ingestedAtTimestamp = $ingestedAt ?? CarbonImmutable::now();

        $event = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'staff_id' => $staffId,
            'source' => $source,
            'device_ref' => $deviceRef,
            'external_event_id' => $externalEventId,
            'direction' => $direction,
            'occurred_at' => $occurredAt,
            'ingested_at' => $ingestedAtTimestamp,
            'raw_payload' => $rawPayload,
            'notes' => $notes,
            'version' => 0,
        ]);

        $event->id = $id;

        $event->recordThat(new AttendanceEventIngested(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            attendanceEventId: $id,
            facilityId: $facilityId,
            staffId: $staffId,
            source: $source->value,
            direction: $direction->value,
            occurredAt: $occurredAt->toIso8601String(),
            ingestedAt: $ingestedAtTimestamp->toIso8601String(),
            externalEventId: $externalEventId,
        ));

        return $event;
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
