<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\TriageAssessmentCorrected;
use App\Domains\Clinical\Contracts\Event\TriageAssessmentRecorded;
use App\Domains\Clinical\Database\Factories\TriageAssessmentFactory;
use App\Domains\Clinical\Domain\Exception\TriageAssessmentAlreadySuperseded;
use App\Domains\Clinical\Domain\ValueObject\TriagePriority;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One triage assessment — a rapid, point-in-time, structured clinical
 * conclusion recorded when a patient presents for care (ADR-105; ADR-145;
 * 11-encounter-domain.md §166 / §53; 12-clinical-domain.md §16 / §28 / §49 /
 * §871 / §1895).
 *
 * A partitioned aggregate (ADR-131) — its own table `clin_triage_assessments`,
 * NOT a `ClinicalNote note_type=triage`, NOT a `VitalSignsObservation`. Many
 * triage assessments may exist for one encounter (re-triage); there is NO
 * uniqueness constraint. Every assessment references exactly one
 * `encounter_id` — encounter-scoped, NOT longitudinal (ADR-145 D-D).
 *
 * LIFECYCLE (ADR-145 D-F): a single implicit state — the row exists <=> the
 * assessment is `RECORDED`. Its CLINICAL CONTENT (`chief_complaint`,
 * `triage_priority`, `acuity`, `consciousness`, `source_vitals_observation_id`,
 * `occurred_at`, `recorded_at`, `authored_context` and every identity field) is
 * IMMUTABLE after recording. There is no status column. The ONLY permitted
 * post-creation mutation is the ONE-TIME supersession stamp
 * ({@see markSupersededBy()}).
 *
 * CORRECTION / RE-TRIAGE (ADR-145 D-F): {@see correcting()} builds a NEW
 * `TriageAssessment` carrying the current values plus
 * `supersedes_triage_assessment_id` and a mandatory `reason`; the persisting
 * repository also stamps this (the original) row. There is NO
 * `UpdateTriageAssessment` / `CancelTriageAssessment` / `VoidTriageAssessment`,
 * NO in-place clinical mutation, NO history table. "Current triage" for an
 * encounter is the most recent assessment with `superseded_at IS NULL`.
 *
 * `authored_context` is `CLINICIAN` (default) or `NURSING` (ADR-145 D-M — the
 * ADR-144 mechanism; triage is frequently nurse-performed) — caller-supplied,
 * set-membership-validated, NOT an authorization input.
 *
 * `casts()` (the method) layers the date casts over AggregateRoot's own
 * `$casts`; Larastan reads the property, so the effective shape is pinned in
 * `@property` tags.
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
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property CarbonImmutable|null $superseded_at
 */
final class TriageAssessment extends AggregateRoot
{
    /** @use HasFactory<TriageAssessmentFactory> */
    use HasFactory;

    /**
     * The authoring context the write path accepts (ADR-145 D-M — the ADR-144
     * `{CLINICIAN, NURSING}` mechanism). Caller-supplied, set-membership-
     * validated, NOT an authorization input (no `IdentityApi` / role check);
     * it never branches lifecycle, authorization, validation or handler logic.
     */
    public const AUTHORED_CONTEXT_CLINICIAN = 'CLINICIAN';

    public const AUTHORED_CONTEXT_NURSING = 'NURSING';

    /** @var list<string> */
    public const AUTHORED_CONTEXTS = [self::AUTHORED_CONTEXT_CLINICIAN, self::AUTHORED_CONTEXT_NURSING];

    /** Open coded strings — shape-validated only, never a scale enum (ADR-145 O-1). */
    private const MAX_CHIEF_COMPLAINT_LENGTH = 2000;

    private const MAX_ACUITY_LENGTH = 100;

    private const MAX_CONSCIOUSNESS_LENGTH = 100;

    protected $table = 'clin_triage_assessments';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'author_user_id', 'authored_context',
        'chief_complaint', 'triage_priority', 'acuity', 'consciousness',
        'source_vitals_observation_id',
        'supersedes_triage_assessment_id', 'superseded_at', 'superseded_by_triage_assessment_id', 'reason',
        'occurred_at', 'recorded_at',
    ];

    protected static function newFactory(): TriageAssessmentFactory
    {
        return TriageAssessmentFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Record a new triage assessment (ADR-145 D-G). `$id` is client-minted (a
     * UUIDv7) so the `Auditable` `RecordTriageAssessment` command names its
     * target before the row exists. Patient eligibility, the encounter-state
     * gate, patient / encounter consistency and the optional vitals-reference
     * check are the handler's responsibility (via `PatientApi` / `EncounterApi`
     * / the C.2 reader); `$facilityId` is the encounter context's facility
     * (ADR-135). `$occurredAt` is the clinical time the triage was performed
     * (never future — validated on the command); `$recordedAt` is server write
     * time.
     */
    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $authorUserId,
        string $authoredContext,
        string $chiefComplaint,
        string $triagePriority,
        ?string $acuity,
        ?string $consciousness,
        ?string $sourceVitalsObservationId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        self::assertAuthoredContext($authoredContext);
        $chiefComplaint = self::assertChiefComplaint($chiefComplaint);
        $triagePriority = TriagePriority::assert($triagePriority);
        $acuity = self::normalizeOptional($acuity, self::MAX_ACUITY_LENGTH, 'acuity');
        $consciousness = self::normalizeOptional($consciousness, self::MAX_CONSCIOUSNESS_LENGTH, 'consciousness');

        $assessment = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'author_user_id' => $authorUserId,
            'authored_context' => $authoredContext,
            'chief_complaint' => $chiefComplaint,
            'triage_priority' => $triagePriority,
            'acuity' => $acuity,
            'consciousness' => $consciousness,
            'source_vitals_observation_id' => $sourceVitalsObservationId,
            'supersedes_triage_assessment_id' => null,
            'superseded_at' => null,
            'superseded_by_triage_assessment_id' => null,
            'reason' => null,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $assessment->recordThat(new TriageAssessmentRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            triageAssessmentId: $id,
            triagePriority: $triagePriority,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $assessment;
    }

    /**
     * Build the NEW triage assessment that corrects / re-triages (supersedes)
     * `$original` (ADR-145 D-F). The context (tenant / facility / patient /
     * encounter) is copied from the original — a superseding assessment restates
     * the same triage event on the same attendance, so it is not re-gated
     * against the encounter (the ADR-135 post-origination principle; the C.2 /
     * C.3 precedent). `$reason` is mandatory and is CLINICAL data stored on this
     * new row (never on the audit, never on the event).
     *
     * The caller (`CorrectTriageAssessmentHandler`) has already loaded
     * `$original` (RLS-scoped) and asserted it is not already superseded. The
     * optional `$sourceVitalsObservationId` is re-supplied by the caller and
     * revalidated by the handler.
     */
    public static function correcting(
        string $id,
        self $original,
        string $authorUserId,
        string $authoredContext,
        string $chiefComplaint,
        string $triagePriority,
        ?string $acuity,
        ?string $consciousness,
        ?string $sourceVitalsObservationId,
        string $reason,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        self::assertAuthoredContext($authoredContext);
        $chiefComplaint = self::assertChiefComplaint($chiefComplaint);
        $triagePriority = TriagePriority::assert($triagePriority);
        $acuity = self::normalizeOptional($acuity, self::MAX_ACUITY_LENGTH, 'acuity');
        $consciousness = self::normalizeOptional($consciousness, self::MAX_CONSCIOUSNESS_LENGTH, 'consciousness');

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A correction / re-triage reason is required (ADR-145 D-F).');
        }

        $assessment = new self([
            'id' => $id,
            'tenant_id' => (string) $original->tenant_id,
            'facility_id' => (string) $original->facility_id,
            'patient_id' => (string) $original->patient_id,
            'encounter_id' => (string) $original->encounter_id,
            'author_user_id' => $authorUserId,
            'authored_context' => $authoredContext,
            'chief_complaint' => $chiefComplaint,
            'triage_priority' => $triagePriority,
            'acuity' => $acuity,
            'consciousness' => $consciousness,
            'source_vitals_observation_id' => $sourceVitalsObservationId,
            'supersedes_triage_assessment_id' => (string) $original->id,
            'superseded_at' => null,
            'superseded_by_triage_assessment_id' => null,
            'reason' => trim($reason),
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $assessment->recordThat(new TriageAssessmentCorrected(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $original->tenant_id,
            facilityId: (string) $original->facility_id,
            patientId: (string) $original->patient_id,
            encounterId: (string) $original->encounter_id,
            triageAssessmentId: $id,
            supersedesTriageAssessmentId: (string) $original->id,
            triagePriority: $triagePriority,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $assessment;
    }

    /**
     * The ONE-TIME supersession stamp (ADR-145 D-F). Sets ONLY `superseded_at`
     * (server `now()`) and `superseded_by_triage_assessment_id`; the clinical
     * content and every other field stay immutable. Records NO event (the new
     * assessment records `TriageAssessmentCorrected`). A second call is a bug
     * and is rejected.
     */
    public function markSupersededBy(string $newTriageAssessmentId): void
    {
        if ($this->superseded_at !== null) {
            throw TriageAssessmentAlreadySuperseded::for(
                (string) $this->id,
                (string) $this->superseded_by_triage_assessment_id,
            );
        }

        $this->superseded_at = CarbonImmutable::now();
        $this->superseded_by_triage_assessment_id = $newTriageAssessmentId;
    }

    /** This assessment has been superseded by a later (re-)triage assessment. */
    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /** This assessment is itself a correction / re-triage of an earlier one. */
    public function isCorrection(): bool
    {
        return $this->supersedes_triage_assessment_id !== null;
    }

    private static function assertAuthoredContext(string $authoredContext): void
    {
        if (! in_array($authoredContext, self::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'authored_context must be one of %s (ADR-145 D-M).',
                implode(' / ', self::AUTHORED_CONTEXTS),
            ));
        }
    }

    private static function assertChiefComplaint(string $chiefComplaint): string
    {
        $trimmed = trim($chiefComplaint);

        if ($trimmed === '') {
            throw new InvalidArgumentException('TriageAssessment::$chiefComplaint must not be blank (ADR-145 D-E).');
        }

        if (mb_strlen($trimmed) > self::MAX_CHIEF_COMPLAINT_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'TriageAssessment::$chiefComplaint must be at most %d characters (ADR-145 D-E).',
                self::MAX_CHIEF_COMPLAINT_LENGTH,
            ));
        }

        return $trimmed;
    }

    private static function normalizeOptional(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (mb_strlen($trimmed) > $maxLength) {
            throw new InvalidArgumentException(sprintf(
                'TriageAssessment::$%s must be at most %d characters (ADR-145 O-1).',
                $field,
                $maxLength,
            ));
        }

        return $trimmed;
    }
}
