<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain\Model;

use App\Domains\Theatre\Contracts\Event\SurgicalProcedureAborted;
use App\Domains\Theatre\Contracts\Event\SurgicalProcedureCompleted;
use App\Domains\Theatre\Contracts\Event\SurgicalProcedureRecorded;
use App\Domains\Theatre\Contracts\Event\SurgicalProcedureStarted;
use App\Domains\Theatre\Database\Factories\SurgicalProcedureFactory;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * SurgicalProcedure aggregate root (17-theatre-domain.md §10, §213; ADR-156
 * D-C) — one operation performed during a surgical case; a case may have
 * several. ADDITIVE — the folded T.1 `SurgicalCase` columns are unchanged.
 *
 * Emits SurgicalProcedureRecorded / Started / Completed / Aborted (PHI-safe —
 * descriptions / findings / complications NEVER carried).
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
 * @property string $case_id
 * @property string $patient_id
 * @property string $encounter_id
 * @property int $sequence
 * @property string|null $planned_description
 * @property string|null $actual_description
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property string|null $primary_surgeon_user_id
 * @property string|null $outcome
 * @property string|null $findings
 * @property string|null $complications
 * @property string $status
 * @property string $created_by_user_id
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $recorded_at
 * @property int $version
 */
final class SurgicalProcedure extends AggregateRoot
{
    /** @use HasFactory<SurgicalProcedureFactory> */
    use HasFactory;

    public const STATUS_PLANNED = 'PLANNED';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_ABORTED = 'ABORTED';

    protected $table = 'thr_surgical_procedures';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'tenant_id',
        'facility_id',
        'case_id',
        'patient_id',
        'encounter_id',
        'sequence',
        'planned_description',
        'actual_description',
        'started_at',
        'ended_at',
        'primary_surgeon_user_id',
        'outcome',
        'findings',
        'complications',
        'status',
        'created_by_user_id',
        'occurred_at',
        'recorded_at',
        'version',
    ];

    protected static function newFactory(): SurgicalProcedureFactory
    {
        return SurgicalProcedureFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $caseId,
        string $patientId,
        string $encounterId,
        int $sequence,
        ?string $plannedDescription,
        ?string $primarySurgeonUserId,
        string $createdByUserId,
        ?CarbonImmutable $occurredAt = null,
    ): self {
        $occurred = $occurredAt ?? CarbonImmutable::now();
        $recorded = CarbonImmutable::now();

        $procedure = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'case_id' => $caseId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'sequence' => $sequence,
            'planned_description' => $plannedDescription,
            'actual_description' => null,
            'started_at' => null,
            'ended_at' => null,
            'primary_surgeon_user_id' => $primarySurgeonUserId,
            'outcome' => null,
            'findings' => null,
            'complications' => null,
            'status' => self::STATUS_PLANNED,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurred,
            'recorded_at' => $recorded,
        ]);

        $procedure->recordThat(new SurgicalProcedureRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            surgicalProcedureId: $id,
            caseId: $caseId,
            patientId: $patientId,
            encounterId: $encounterId,
            sequence: $sequence,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $procedure;
    }

    public function start(?string $actualDescription): void
    {
        $this->status = self::STATUS_IN_PROGRESS;
        $this->started_at = CarbonImmutable::now();

        if ($actualDescription !== null) {
            $this->actual_description = $actualDescription;
        }

        $this->recordThat(new SurgicalProcedureStarted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            surgicalProcedureId: (string) $this->id,
            caseId: (string) $this->case_id,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function complete(?string $actualDescription, ?string $outcome, ?string $findings, ?string $complications): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->ended_at = CarbonImmutable::now();
        $this->actual_description = $actualDescription ?? $this->actual_description;
        $this->outcome = $outcome;
        $this->findings = $findings;
        $this->complications = $complications;

        $this->recordThat(new SurgicalProcedureCompleted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            surgicalProcedureId: (string) $this->id,
            caseId: (string) $this->case_id,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    public function abort(?string $reason): void
    {
        $this->status = self::STATUS_ABORTED;
        $this->ended_at = CarbonImmutable::now();

        if ($reason !== null) {
            $this->complications = $reason;
        }

        $this->recordThat(new SurgicalProcedureAborted(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            surgicalProcedureId: (string) $this->id,
            caseId: (string) $this->case_id,
            occurredAt: CarbonImmutable::now()->toIso8601String(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }
}
