<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\RosterEntryScheduled;
use App\Domains\HR\Contracts\Event\ShiftCancelled;
use App\Domains\HR\Contracts\Event\ShiftSwapped;
use App\Domains\HR\Domain\Exception\InvalidRosterStatusTransitionException;
use App\Domains\HR\Domain\Exception\ShiftSwapMismatchException;
use App\Domains\HR\Domain\ValueObject\RosterEntryStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Scheduled shift roster entry aggregate root (25-hr-and-workforce-domain.md §6, §7, §8; ADR-206).
 *
 * Lifecycle: DRAFT -> PUBLISHED -> COMPLETED | ABSENT | SWAPPED | CANCELLED.
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
 * @property string|null $department_id
 * @property string|null $unit_id
 * @property string $staff_id
 * @property string $shift_pattern_id
 * @property CarbonImmutable $date
 * @property string $start_time
 * @property string $end_time
 * @property RosterEntryStatus $status
 * @property string|null $cancellation_reason
 * @property string|null $notes
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property Staff|null $staff
 * @property ShiftPattern|null $shiftPattern
 */
final class RosterEntry extends AggregateRoot
{
    protected $table = 'hr_roster_entries';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'unit_id',
        'staff_id',
        'shift_pattern_id',
        'date',
        'start_time',
        'end_time',
        'status',
        'cancellation_reason',
        'notes',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'date' => 'immutable_date',
        'status' => RosterEntryStatus::class,
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function scheduleDraft(
        string $tenantId,
        string $facilityId,
        ?string $departmentId,
        ?string $unitId,
        string $staffId,
        string $shiftPatternId,
        CarbonImmutable $date,
        string $startTime,
        string $endTime,
        ?string $notes = null,
        ?string $rosterEntryId = null,
    ): self {
        $id = $rosterEntryId ?? (string) Str::uuid();

        $entry = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_id' => $departmentId,
            'unit_id' => $unitId,
            'staff_id' => $staffId,
            'shift_pattern_id' => $shiftPatternId,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => RosterEntryStatus::DRAFT,
            'cancellation_reason' => null,
            'notes' => $notes,
            'version' => 0,
        ]);

        $entry->id = $id;

        $entry->recordThat(new RosterEntryScheduled(
            eventId: (string) Str::uuid(),
            tenantId: $tenantId,
            rosterEntryId: $id,
            facilityId: $facilityId,
            departmentId: $departmentId,
            unitId: $unitId,
            staffId: $staffId,
            shiftPatternId: $shiftPatternId,
            date: $date->toDateString(),
            status: RosterEntryStatus::DRAFT->value,
            notes: $notes,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));

        return $entry;
    }

    public function publish(): void
    {
        if ($this->status === RosterEntryStatus::PUBLISHED) {
            return;
        }

        if (! $this->status->canTransitionTo(RosterEntryStatus::PUBLISHED)) {
            throw InvalidRosterStatusTransitionException::forTransition(
                $this->status,
                RosterEntryStatus::PUBLISHED
            );
        }

        $this->status = RosterEntryStatus::PUBLISHED;
    }

    public function swapWith(self $other): void
    {
        if (! $this->status->isPublished() || ! $other->status->isPublished()) {
            throw ShiftSwapMismatchException::notBothPublished($this->id, $other->id);
        }

        if ($this->staff_id === $other->staff_id) {
            throw ShiftSwapMismatchException::sameStaff($this->staff_id);
        }

        if ($this->facility_id !== $other->facility_id) {
            throw ShiftSwapMismatchException::facilityMismatch($this->facility_id, $other->facility_id);
        }

        $staffAId = $this->staff_id;
        $staffBId = $other->staff_id;

        // Exchange staff assignments
        $this->staff_id = $staffBId;
        $other->staff_id = $staffAId;

        $now = CarbonImmutable::now();
        $this->notes = trim(($this->notes ? $this->notes.'; ' : '').sprintf('Swapped from staff [%s] to [%s] via entry [%s]', $staffAId, $staffBId, $other->id));
        $other->notes = trim(($other->notes ? $other->notes.'; ' : '').sprintf('Swapped from staff [%s] to [%s] via entry [%s]', $staffBId, $staffAId, $this->id));

        $this->recordThat(new ShiftSwapped(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            entryAId: $this->id,
            staffAId: $staffAId,
            entryBId: $other->id,
            staffBId: $staffBId,
            swappedAt: $now->toIso8601String(),
            occurredAt: $now->toIso8601String(),
        ));
    }

    public function cancel(string $reason): void
    {
        if (! $this->status->canTransitionTo(RosterEntryStatus::CANCELLED)) {
            throw InvalidRosterStatusTransitionException::forTransition(
                $this->status,
                RosterEntryStatus::CANCELLED
            );
        }

        $this->status = RosterEntryStatus::CANCELLED;
        $this->cancellation_reason = trim($reason);

        $now = CarbonImmutable::now();

        $this->recordThat(new ShiftCancelled(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            rosterEntryId: $this->id,
            staffId: $this->staff_id,
            date: $this->date->toDateString(),
            cancellationReason: $this->cancellation_reason,
            cancelledAt: $now->toIso8601String(),
            occurredAt: $now->toIso8601String(),
        ));
    }

    public function markCompleted(): void
    {
        if (! $this->status->canTransitionTo(RosterEntryStatus::COMPLETED)) {
            throw InvalidRosterStatusTransitionException::forTransition(
                $this->status,
                RosterEntryStatus::COMPLETED
            );
        }

        $this->status = RosterEntryStatus::COMPLETED;
    }

    public function markAbsent(): void
    {
        if (! $this->status->canTransitionTo(RosterEntryStatus::ABSENT)) {
            throw InvalidRosterStatusTransitionException::forTransition(
                $this->status,
                RosterEntryStatus::ABSENT
            );
        }

        $this->status = RosterEntryStatus::ABSENT;
    }

    public function markSwapped(): void
    {
        if (! $this->status->canTransitionTo(RosterEntryStatus::SWAPPED)) {
            throw InvalidRosterStatusTransitionException::forTransition(
                $this->status,
                RosterEntryStatus::SWAPPED
            );
        }

        $this->status = RosterEntryStatus::SWAPPED;
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /**
     * @return BelongsTo<ShiftPattern, $this>
     */
    public function shiftPattern(): BelongsTo
    {
        return $this->belongsTo(ShiftPattern::class, 'shift_pattern_id');
    }
}
