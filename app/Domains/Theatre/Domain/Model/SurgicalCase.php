<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Contracts\Event\SurgicalCaseAborted;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseCancelled;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseCompleted;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseCreated;
use App\Domains\Theatre\Contracts\Event\SurgicalCasePostponed;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseReady;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseRescheduled;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseScheduled;
use App\Domains\Theatre\Contracts\Event\SurgicalCaseStarted;
use App\Domains\Theatre\Database\Factories\SurgicalCaseFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * SurgicalCase aggregate root — the perioperative operational record
 * (17-theatre-domain.md §9, §219; ADR-150 D-C/D-D).
 *
 * The full 13-state lifecycle (D-D) is kept in the STATUS_* constants, but
 * T.1's own 16 commands (D-I) do not traverse every state (PLANNED,
 * RECOVERY, CLOSED are reachable only by T.2+ commands — the same partial-
 * traversal already accepted for events, D-K).
 *
 * Emits SurgicalCaseCreated, SurgicalCaseScheduled, SurgicalCaseRescheduled,
 * SurgicalCaseReady, SurgicalCaseStarted, SurgicalCaseCompleted,
 * SurgicalCaseCancelled, SurgicalCasePostponed, SurgicalCaseAborted.
 *
 * @domain Theatre
 *
 * @authority authoritative
 *
 * @tenant required
 *
 * @facility required
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $facility_id
 * @property string $patient_id
 * @property string $encounter_id
 * @property string|null $operating_room_id
 * @property string $case_number
 * @property string $priority
 * @property CarbonImmutable|null $scheduled_for
 * @property string|null $planned_procedure
 * @property string|null $actual_procedure
 * @property string|null $indication
 * @property string|null $findings
 * @property string|null $outcome
 * @property string|null $complications
 * @property list<array{stage: string, completed_at: string, actor_user_id: string}> $safety_checklist
 * @property string|null $recovery_destination_type
 * @property string $status
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class SurgicalCase extends AggregateRoot
{
    /** @use HasFactory<SurgicalCaseFactory> */
    use HasFactory;

    public const STATUS_REQUESTED = 'REQUESTED';

    public const STATUS_PLANNED = 'PLANNED';

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_PRE_OP = 'PRE_OP';

    public const STATUS_READY = 'READY';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_RECOVERY = 'RECOVERY';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_POSTPONED = 'POSTPONED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_ABORTED = 'ABORTED';

    public const STATUS_NO_SHOW = 'NO_SHOW';

    /** @var list<string> Statuses in which the case is still "active" (ADR-150 D-E). */
    public const ACTIVE_STATUSES = [self::STATUS_READY, self::STATUS_IN_PROGRESS];

    /** @var list<string> Terminal statuses — no further transition is legal. */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CLOSED,
        self::STATUS_CANCELLED,
        self::STATUS_ABORTED,
        self::STATUS_NO_SHOW,
    ];

    protected $table = 'thr_surgical_cases';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'operating_room_id',
        'case_number',
        'priority',
        'scheduled_for',
        'planned_procedure',
        'actual_procedure',
        'indication',
        'findings',
        'outcome',
        'complications',
        'safety_checklist',
        'recovery_destination_type',
        'status',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): SurgicalCaseFactory
    {
        return SurgicalCaseFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_for' => 'immutable_datetime',
            'safety_checklist' => 'array',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function create(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $caseNumber,
        string $priority,
        ?string $plannedProcedure,
        ?string $indication,
        string $createdByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $case = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'operating_room_id' => null,
            'case_number' => $caseNumber,
            'priority' => $priority,
            'scheduled_for' => null,
            'planned_procedure' => $plannedProcedure,
            'actual_procedure' => null,
            'indication' => $indication,
            'findings' => null,
            'outcome' => null,
            'complications' => null,
            'safety_checklist' => [],
            'recovery_destination_type' => null,
            'status' => self::STATUS_REQUESTED,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);

        $now = CarbonImmutable::now()->toIso8601String();

        $case->recordThat(new SurgicalCaseCreated(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            caseId: $id,
            patientId: $patientId,
            encounterId: $encounterId,
            caseNumber: $caseNumber,
            priority: $priority,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $case;
    }

    public function assignOperatingRoom(string $operatingRoomId): void
    {
        $this->operating_room_id = $operatingRoomId;
    }

    public function schedule(CarbonImmutable $scheduledFor): void
    {
        $this->status = self::STATUS_SCHEDULED;
        $this->scheduled_for = $scheduledFor;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new SurgicalCaseScheduled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            caseId: (string) $this->id,
            scheduledFor: $scheduledFor->toIso8601String(),
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function reschedule(CarbonImmutable $newScheduledFor): void
    {
        $previous = $this->scheduled_for;
        $this->scheduled_for = $newScheduledFor;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new SurgicalCaseRescheduled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            caseId: (string) $this->id,
            previousScheduledFor: $previous?->toIso8601String(),
            newScheduledFor: $newScheduledFor->toIso8601String(),
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function startPreOp(): void
    {
        $this->status = self::STATUS_PRE_OP;
    }

    /**
     * @param  array{stage: string, completed_at: string, actor_user_id: string}  $entry
     */
    public function completeChecklistStage(array $entry): void
    {
        $checklist = $this->safety_checklist;
        $checklist[] = $entry;
        $this->safety_checklist = $checklist;
    }

    public function markReady(): void
    {
        $this->status = self::STATUS_READY;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new SurgicalCaseReady(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            caseId: (string) $this->id,
            operatingRoomId: (string) $this->operating_room_id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function start(): void
    {
        $this->status = self::STATUS_IN_PROGRESS;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new SurgicalCaseStarted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            caseId: (string) $this->id,
            operatingRoomId: (string) $this->operating_room_id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function complete(
        ?string $actualProcedure,
        ?string $findings,
        ?string $outcome,
        ?string $complications,
        ?string $recoveryDestinationType,
    ): void {
        $this->status = self::STATUS_COMPLETED;
        $this->actual_procedure = $actualProcedure;
        $this->findings = $findings;
        $this->outcome = $outcome;
        $this->complications = $complications;
        $this->recovery_destination_type = $recoveryDestinationType;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new SurgicalCaseCompleted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            caseId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new SurgicalCaseCancelled(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            caseId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function postpone(): void
    {
        $this->status = self::STATUS_POSTPONED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new SurgicalCasePostponed(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            caseId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function abort(): void
    {
        $this->status = self::STATUS_ABORTED;

        $now = CarbonImmutable::now()->toIso8601String();

        $this->recordThat(new SurgicalCaseAborted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            caseId: (string) $this->id,
            occurredAt: $now,
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function markNoShow(): void
    {
        $this->status = self::STATUS_NO_SHOW;
    }
}
