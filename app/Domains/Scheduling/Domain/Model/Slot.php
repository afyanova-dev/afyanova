<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Domain\Model;

use App\Domains\Scheduling\Contracts\Event\SlotHeld;
use App\Domains\Scheduling\Contracts\Event\SlotReleased;
use App\Domains\Scheduling\Domain\Exception\SlotNotAvailableException;
use App\Domains\Scheduling\Domain\ValueObject\SlotStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Bookable slot aggregate root (22-scheduling-and-appointments-domain.md §42–§45).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $resource_id
 * @property string|null $appointment_type_id
 * @property CarbonImmutable $slot_date
 * @property CarbonImmutable $start_time
 * @property CarbonImmutable $end_time
 * @property int $capacity
 * @property int $booked_count
 * @property SlotStatus $status
 * @property CarbonImmutable|null $held_until
 * @property string|null $held_by
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Slot extends AggregateRoot
{
    protected $table = 'sch_slots';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'resource_id',
        'appointment_type_id',
        'slot_date',
        'start_time',
        'end_time',
        'capacity',
        'booked_count',
        'status',
        'held_until',
        'held_by',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'slot_date' => 'immutable_date',
        'start_time' => 'immutable_datetime',
        'end_time' => 'immutable_datetime',
        'capacity' => 'integer',
        'booked_count' => 'integer',
        'status' => SlotStatus::class,
        'held_until' => 'immutable_datetime',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function create(
        string $tenantId,
        string $facilityId,
        string $resourceId,
        string|CarbonImmutable $slotDate,
        string|CarbonImmutable $startTime,
        string|CarbonImmutable $endTime,
        int $capacity = 1,
        ?string $appointmentTypeId = null,
        ?string $slotId = null,
    ): self {
        $id = $slotId ?? (string) Str::uuid();
        $date = is_string($slotDate) ? CarbonImmutable::parse($slotDate) : $slotDate;
        $start = is_string($startTime) ? CarbonImmutable::parse($startTime) : $startTime;
        $end = is_string($endTime) ? CarbonImmutable::parse($endTime) : $endTime;

        $slot = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'resource_id' => $resourceId,
            'appointment_type_id' => $appointmentTypeId,
            'slot_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
            'capacity' => $capacity,
            'booked_count' => 0,
            'status' => SlotStatus::OPEN,
            'version' => 0,
        ]);

        $slot->id = $id;

        return $slot;
    }

    public function isHeld(): bool
    {
        return $this->status === SlotStatus::HELD;
    }

    public function isHoldExpired(): bool
    {
        return $this->status === SlotStatus::HELD
            && $this->held_until !== null
            && $this->held_until->isPast();
    }

    public function isAvailable(): bool
    {
        return ($this->status === SlotStatus::OPEN || $this->isHoldExpired())
            && $this->booked_count < $this->capacity;
    }

    public function hold(string $heldBy, CarbonImmutable $heldUntil): void
    {
        // If slot is held but the hold has expired, it can be claimed
        $isExpiredHold = $this->status === SlotStatus::HELD
            && $this->held_until !== null
            && $this->held_until->isPast();

        if ($this->status !== SlotStatus::OPEN && ! $isExpiredHold) {
            throw SlotNotAvailableException::forSlot($this->id, $this->status->value);
        }

        $this->status = SlotStatus::HELD;
        $this->held_by = $heldBy;
        $this->held_until = $heldUntil;

        $this->recordThat(new SlotHeld(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            slotId: $this->id,
            heldBy: $heldBy,
            heldUntil: $heldUntil->toIso8601String(),
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function releaseHold(string $reason = 'Hold released'): void
    {
        if ($this->status !== SlotStatus::HELD) {
            return;
        }

        $this->status = SlotStatus::OPEN;
        $this->held_by = null;
        $this->held_until = null;

        $this->recordThat(new SlotReleased(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            slotId: $this->id,
            reason: $reason,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function book(): void
    {
        if ($this->status === SlotStatus::BLOCKED) {
            throw SlotNotAvailableException::forSlot($this->id, $this->status->value);
        }

        if ($this->booked_count >= $this->capacity) {
            throw SlotNotAvailableException::forSlot($this->id, $this->status->value);
        }

        $this->booked_count++;
        if ($this->booked_count >= $this->capacity) {
            $this->status = SlotStatus::BOOKED;
            $this->held_by = null;
            $this->held_until = null;
        }
    }

    public function cancelBooking(): void
    {
        if ($this->booked_count > 0) {
            $this->booked_count--;
        }

        if ($this->status === SlotStatus::BOOKED && $this->booked_count < $this->capacity) {
            $this->status = SlotStatus::OPEN;
        }
    }

    public function block(): void
    {
        $this->status = SlotStatus::BLOCKED;
    }
}
