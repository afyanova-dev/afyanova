<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\Model;

use App\Domains\HR\Contracts\Event\AttendanceExceptionRaised;
use App\Domains\HR\Contracts\Event\AttendanceSessionClosed;
use App\Domains\HR\Contracts\Event\AttendanceSessionReconciled;
use App\Domains\HR\Domain\Exception\InvalidAttendanceSessionTransitionException;
use App\Domains\HR\Domain\ValueObject\AttendanceExceptionType;
use App\Domains\HR\Domain\ValueObject\AttendanceSessionStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Derived attendance session aggregate root (25-hr-and-workforce-domain.md §6, §7.11).
 *
 * Operational pairing of clock-in and clock-out events into a workable shift record.
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
 * @property string|null $roster_entry_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int $break_minutes
 * @property int $worked_minutes
 * @property AttendanceExceptionType $exception
 * @property list<string> $source_event_ids
 * @property AttendanceSessionStatus $status
 * @property string|null $reconciliation_note
 * @property string|null $reconciled_by
 * @property CarbonImmutable|null $reconciled_at
 * @property int $version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property Staff|null $staff
 * @property RosterEntry|null $rosterEntry
 */
final class AttendanceSession extends AggregateRoot
{
    protected $table = 'hr_attendance_sessions';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'staff_id',
        'roster_entry_id',
        'started_at',
        'ended_at',
        'break_minutes',
        'worked_minutes',
        'exception',
        'source_event_ids',
        'status',
        'reconciliation_note',
        'reconciled_by',
        'reconciled_at',
        'version',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'started_at' => 'immutable_datetime',
        'ended_at' => 'immutable_datetime',
        'break_minutes' => 'integer',
        'worked_minutes' => 'integer',
        'exception' => AttendanceExceptionType::class,
        'source_event_ids' => 'array',
        'status' => AttendanceSessionStatus::class,
        'reconciled_at' => 'immutable_datetime',
        'version' => 'integer',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public static function start(
        string $tenantId,
        string $facilityId,
        string $staffId,
        CarbonImmutable $startedAt,
        string $startEventId,
        ?string $rosterEntryId = null,
        ?AttendanceExceptionType $exception = null,
        ?string $sessionId = null,
    ): self {
        $id = $sessionId ?? (string) Str::uuid();

        $session = new self([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'staff_id' => $staffId,
            'roster_entry_id' => $rosterEntryId,
            'started_at' => $startedAt,
            'ended_at' => null,
            'break_minutes' => 0,
            'worked_minutes' => 0,
            'exception' => $exception ?? AttendanceExceptionType::NONE,
            'source_event_ids' => [$startEventId],
            'status' => AttendanceSessionStatus::OPEN,
            'reconciliation_note' => null,
            'reconciled_by' => null,
            'reconciled_at' => null,
            'version' => 0,
        ]);

        $session->id = $id;

        return $session;
    }

    public function addEvent(string $eventId): void
    {
        $events = $this->source_event_ids;
        if (! in_array($eventId, $events, true)) {
            $events[] = $eventId;
            $this->source_event_ids = $events;
        }
    }

    public function addBreakMinutes(int $minutes): void
    {
        $this->break_minutes = max(0, $this->break_minutes + $minutes);
    }

    public function close(
        CarbonImmutable $endedAt,
        string $endEventId,
        ?AttendanceExceptionType $exception = null,
    ): void {
        if (! $this->status->canTransitionTo(AttendanceSessionStatus::CLOSED)) {
            throw InvalidAttendanceSessionTransitionException::forTransition(
                $this->status,
                AttendanceSessionStatus::CLOSED
            );
        }

        $this->addEvent($endEventId);
        $this->ended_at = $endedAt;

        $totalMinutes = (int) $this->started_at->diffInMinutes($endedAt);
        $this->worked_minutes = max(0, $totalMinutes - $this->break_minutes);
        $this->status = AttendanceSessionStatus::CLOSED;

        if ($exception !== null) {
            $this->exception = $exception;
        }

        $this->recordThat(new AttendanceSessionClosed(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            attendanceSessionId: $this->id,
            facilityId: $this->facility_id,
            staffId: $this->staff_id,
            rosterEntryId: $this->roster_entry_id,
            startedAt: $this->started_at->toIso8601String(),
            endedAt: $endedAt->toIso8601String(),
            breakMinutes: $this->break_minutes,
            workedMinutes: $this->worked_minutes,
            exception: $this->exception->value,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function reconcile(
        string $reconciledBy,
        string $note,
        ?int $adjustedWorkedMinutes = null,
        ?AttendanceExceptionType $adjustedException = null,
    ): void {
        if (! $this->status->canTransitionTo(AttendanceSessionStatus::RECONCILED)) {
            throw InvalidAttendanceSessionTransitionException::forTransition(
                $this->status,
                AttendanceSessionStatus::RECONCILED
            );
        }

        $this->status = AttendanceSessionStatus::RECONCILED;
        $this->reconciled_by = $reconciledBy;
        $this->reconciliation_note = trim($note);
        $this->reconciled_at = CarbonImmutable::now();

        if ($adjustedWorkedMinutes !== null) {
            $this->worked_minutes = max(0, $adjustedWorkedMinutes);
        }

        if ($adjustedException !== null) {
            $this->exception = $adjustedException;
        }

        $this->recordThat(new AttendanceSessionReconciled(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            attendanceSessionId: $this->id,
            facilityId: $this->facility_id,
            staffId: $this->staff_id,
            reconciledBy: $reconciledBy,
            reconciliationNote: $this->reconciliation_note,
            workedMinutes: $this->worked_minutes,
            exception: $this->exception->value,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    public function raiseException(AttendanceExceptionType $exceptionType): void
    {
        $this->exception = $exceptionType;

        $this->recordThat(new AttendanceExceptionRaised(
            eventId: (string) Str::uuid(),
            tenantId: $this->tenant_id,
            attendanceSessionId: $this->id,
            facilityId: $this->facility_id,
            staffId: $this->staff_id,
            exceptionType: $exceptionType->value,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
        ));
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    /**
     * @return BelongsTo<RosterEntry, $this>
     */
    public function rosterEntry(): BelongsTo
    {
        return $this->belongsTo(RosterEntry::class, 'roster_entry_id');
    }
}
