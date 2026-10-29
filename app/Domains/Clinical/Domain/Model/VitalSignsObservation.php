<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\VitalSignsObservationCorrected;
use App\Domains\Clinical\Contracts\Event\VitalSignsRecorded;
use App\Domains\Clinical\Database\Factories\VitalSignsObservationFactory;
use App\Domains\Clinical\Domain\Exception\VitalSignsObservationAlreadySuperseded;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One complete vitals set recorded at approximately one clinical time by one
 * recorder (12-clinical-domain.md §17–§22; ADR-131, ADR-132, ADR-138).
 *
 * A partitioned aggregate (ADR-131) — its own table `clin_vital_sign_observations`,
 * not part of `ClinicalNote`. The ADR-132 context / attribution / clinical-time
 * shape plus nine nullable typed measurement columns (wide model — no child
 * rows). BMI is derived, never stored.
 *
 * LIFECYCLE (ADR-138 D6): a single state — the row exists <=> the vitals set is
 * `RECORDED`. Measurement values are IMMUTABLE. There is no `status` column.
 * The only permitted post-creation mutation is the ONE-TIME supersession stamp
 * ({@see markSupersededBy()}) when a later correction supersedes this
 * observation.
 *
 * CORRECTION (ADR-138 D7): {@see correcting()} builds a NEW observation that
 * carries the corrected values plus `supersedes_observation_id` and a mandatory
 * `reason`; the persisting repository also stamps this (the original) row.
 *
 * `casts()` (the method) layers the enum / date / numeric casts over
 * AggregateRoot's own `$casts`; Larastan reads the property, so the effective
 * shape is pinned in `@property` tags.
 *
 * @domain      Clinical
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required — from the encounter context (ADR-135), never client
 *              input (Invariant 12).
 *
 * @property string|null $temperature
 * @property int|null $heart_rate
 * @property int|null $respiratory_rate
 * @property int|null $systolic_bp
 * @property int|null $diastolic_bp
 * @property int|null $oxygen_saturation
 * @property string|null $weight
 * @property string|null $height
 * @property int|null $pain_score
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $superseded_at
 */
final class VitalSignsObservation extends AggregateRoot
{
    /** @use HasFactory<VitalSignsObservationFactory> */
    use HasFactory;

    /**
     * The open coded human recording contexts C.2 accepts (ADR-138 D8 —
     * `authored_context` only; device / method / site metadata deferred).
     */
    public const AUTHORED_CONTEXT_CLINICIAN = 'CLINICIAN';

    public const AUTHORED_CONTEXT_NURSING = 'NURSING';

    /** @var list<string> */
    public const AUTHORED_CONTEXTS = [self::AUTHORED_CONTEXT_CLINICIAN, self::AUTHORED_CONTEXT_NURSING];

    protected $table = 'clin_vital_sign_observations';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'author_user_id', 'authored_context',
        'temperature', 'heart_rate', 'respiratory_rate', 'systolic_bp', 'diastolic_bp',
        'oxygen_saturation', 'weight', 'height', 'pain_score',
        'supersedes_observation_id', 'superseded_at', 'superseded_by_observation_id', 'reason',
        'occurred_at', 'recorded_at',
    ];

    protected static function newFactory(): VitalSignsObservationFactory
    {
        return VitalSignsObservationFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'temperature' => 'decimal:1',
            'heart_rate' => 'integer',
            'respiratory_rate' => 'integer',
            'systolic_bp' => 'integer',
            'diastolic_bp' => 'integer',
            'oxygen_saturation' => 'integer',
            'weight' => 'decimal:1',
            'height' => 'decimal:1',
            'pain_score' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Record a new vitals set (ADR-138). `$id` is client-minted (a UUIDv7) so
     * the `Auditable` `RecordVitalSigns` command names its target before the row
     * exists. Patient eligibility, the encounter-state gate and patient /
     * encounter consistency are the handler's responsibility (via `PatientApi` /
     * `EncounterApi`); `$facilityId` is the encounter context's facility
     * (ADR-135). `$occurredAt` is the clinical time of the set (never future —
     * validated on the command); `$recordedAt` is server write time.
     */
    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $authorUserId,
        string $authoredContext,
        VitalSigns $measurements,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        self::assertAuthoredContext($authoredContext);

        $observation = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'author_user_id' => $authorUserId,
            'authored_context' => $authoredContext,
            ...$measurements->toColumns(),
            'supersedes_observation_id' => null,
            'superseded_at' => null,
            'superseded_by_observation_id' => null,
            'reason' => null,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $observation->recordThat(new VitalSignsRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            vitalSignsObservationId: $id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $observation;
    }

    /**
     * Build the NEW observation that corrects (supersedes) `$original`
     * (ADR-138 D7). The context (tenant / facility / patient / encounter) is
     * copied from the original — a correction restates the SAME clinical event,
     * so it is not re-gated against the encounter (the ADR-135 post-origination
     * principle; the C.1 `AmendClinicalNote` precedent). `$reason` is mandatory
     * and is CLINICAL data stored on this new row (never on the audit).
     *
     * The caller (`CorrectVitalSignsObservationHandler`) has already loaded
     * `$original` (RLS-scoped) and asserted it is not already superseded.
     */
    public static function correcting(
        string $id,
        self $original,
        string $authorUserId,
        string $authoredContext,
        VitalSigns $measurements,
        string $reason,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        self::assertAuthoredContext($authoredContext);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A correction reason is required (ADR-138 D7).');
        }

        $observation = new self([
            'id' => $id,
            'tenant_id' => (string) $original->tenant_id,
            'facility_id' => (string) $original->facility_id,
            'patient_id' => (string) $original->patient_id,
            'encounter_id' => (string) $original->encounter_id,
            'author_user_id' => $authorUserId,
            'authored_context' => $authoredContext,
            ...$measurements->toColumns(),
            'supersedes_observation_id' => (string) $original->id,
            'superseded_at' => null,
            'superseded_by_observation_id' => null,
            'reason' => trim($reason),
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $observation->recordThat(new VitalSignsObservationCorrected(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $original->tenant_id,
            facilityId: (string) $original->facility_id,
            patientId: (string) $original->patient_id,
            encounterId: (string) $original->encounter_id,
            vitalSignsObservationId: $id,
            supersedesObservationId: (string) $original->id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $observation;
    }

    /**
     * The ONE-TIME supersession stamp (ADR-138 D7 / item B). Sets ONLY
     * `superseded_at` (server `now()`) and `superseded_by_observation_id`; the
     * measurement values and all other context stay immutable. Records NO event
     * (the new observation records `VitalSignsObservationCorrected`). A second
     * call is a bug and is rejected.
     */
    public function markSupersededBy(string $newObservationId): void
    {
        if ($this->superseded_at !== null) {
            throw VitalSignsObservationAlreadySuperseded::for(
                (string) $this->id,
                (string) $this->superseded_by_observation_id,
            );
        }

        $this->superseded_at = CarbonImmutable::now();
        $this->superseded_by_observation_id = $newObservationId;
    }

    /** This observation has been corrected by a later (superseding) observation. */
    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /** This observation is itself a correction of an earlier observation. */
    public function isCorrection(): bool
    {
        return $this->supersedes_observation_id !== null;
    }

    private static function assertAuthoredContext(string $authoredContext): void
    {
        if (! in_array($authoredContext, self::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'authored_context must be one of %s (ADR-138 D8).',
                implode(' / ', self::AUTHORED_CONTEXTS),
            ));
        }
    }
}
