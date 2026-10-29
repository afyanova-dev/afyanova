<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Model;

use App\Domains\CareCoordination\Contracts\Event\FollowUpKept;
use App\Domains\CareCoordination\Contracts\Event\FollowUpMissed;
use App\Domains\CareCoordination\Contracts\Event\FollowUpScheduled;
use App\Domains\CareCoordination\Database\Factories\FollowUpFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * FollowUp aggregate root (26-care-coordination-domain.md §6, §8; ADR-154
 * D-B/D-C) — "a follow-up is due; was it kept?".
 *
 * Emits FollowUpScheduled on creation, FollowUpKept / FollowUpMissed on a
 * KEPT / MISSED outcome. CANCELLED / NOT_REQUIRED are audit-only (§315 names
 * no event — the T.2 UpdateOperativeNoteContent precedent).
 *
 * @domain CareCoordination
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
 * @property string $source_encounter_id
 * @property CarbonImmutable|null $due_from
 * @property CarbonImmutable|null $due_to
 * @property string|null $reason
 * @property string|null $instructions
 * @property string|null $booked_appointment_id
 * @property string|null $resulting_encounter_id
 * @property string $status
 * @property CarbonImmutable|null $outcome_recorded_at
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property CarbonImmutable|null $updated_at
 * @property int $version
 */
final class FollowUp extends AggregateRoot
{
    /** @use HasFactory<FollowUpFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_KEPT = 'KEPT';

    public const STATUS_MISSED = 'MISSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_NOT_REQUIRED = 'NOT_REQUIRED';

    /** @var list<string> The outcomes RecordFollowUpOutcome accepts (ADR-154 D-C). */
    public const OUTCOMES = [
        self::STATUS_KEPT,
        self::STATUS_MISSED,
        self::STATUS_CANCELLED,
        self::STATUS_NOT_REQUIRED,
    ];

    protected $table = 'care_followups';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'patient_id',
        'source_encounter_id',
        'due_from',
        'due_to',
        'reason',
        'instructions',
        'booked_appointment_id',
        'resulting_encounter_id',
        'status',
        'outcome_recorded_at',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): FollowUpFactory
    {
        return FollowUpFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_from' => 'immutable_date',
            'due_to' => 'immutable_date',
            'outcome_recorded_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function schedule(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $sourceEncounterId,
        CarbonImmutable $dueFrom,
        CarbonImmutable $dueTo,
        ?string $reason,
        ?string $instructions,
        string $createdByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $followUp = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'source_encounter_id' => $sourceEncounterId,
            'due_from' => $dueFrom,
            'due_to' => $dueTo,
            'reason' => $reason,
            'instructions' => $instructions,
            'booked_appointment_id' => null,
            'resulting_encounter_id' => null,
            'status' => self::STATUS_PENDING,
            'outcome_recorded_at' => null,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);

        $followUp->recordThat(new FollowUpScheduled(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            followUpId: $id,
            patientId: $patientId,
            sourceEncounterId: $sourceEncounterId,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $followUp;
    }

    public function recordOutcome(string $outcome, ?string $resultingEncounterId): void
    {
        $this->status = $outcome;
        $this->outcome_recorded_at = CarbonImmutable::now();

        if ($outcome === self::STATUS_KEPT) {
            $this->resulting_encounter_id = $resultingEncounterId;
        }

        $now = CarbonImmutable::now()->toIso8601String();

        if ($outcome === self::STATUS_KEPT) {
            $this->recordThat(new FollowUpKept(
                eventId: (string) Str::uuid7(),
                tenantId: (string) $this->tenant_id,
                facilityId: (string) $this->facility_id,
                followUpId: (string) $this->id,
                patientId: (string) $this->patient_id,
                resultingEncounterId: (string) $resultingEncounterId,
                occurredAt: $now,
                correlationId: CorrelationId::get(),
                causationId: Causation::get(),
            ));
        } elseif ($outcome === self::STATUS_MISSED) {
            $this->recordThat(new FollowUpMissed(
                eventId: (string) Str::uuid7(),
                tenantId: (string) $this->tenant_id,
                facilityId: (string) $this->facility_id,
                followUpId: (string) $this->id,
                patientId: (string) $this->patient_id,
                occurredAt: $now,
                correlationId: CorrelationId::get(),
                causationId: Causation::get(),
            ));
        }
    }
}
