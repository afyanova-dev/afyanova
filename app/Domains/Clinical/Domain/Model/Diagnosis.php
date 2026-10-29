<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\DiagnosisCorrected;
use App\Domains\Clinical\Contracts\Event\DiagnosisRecorded;
use App\Domains\Clinical\Database\Factories\DiagnosisFactory;
use App\Domains\Clinical\Domain\Exception\DiagnosisAlreadySuperseded;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One encounter-bound diagnosis assertion — one discrete coded clinical
 * conclusion recorded during care (12-clinical-domain.md §23–§27, §73, §102;
 * ADR-131, ADR-132, ADR-135, ADR-139).
 *
 * A partitioned aggregate (ADR-131) — its own table `clin_diagnoses`, not part
 * of `ClinicalNote`, not a `Problem`. Many diagnoses may exist for one
 * encounter; there is NO diagnosis-list aggregate and NO uniqueness constraint.
 * Every diagnosis references exactly one `encounter_id`; a longitudinal /
 * encounterless diagnosis is NOT PHASE 6 (ADR-135; §26, §106).
 *
 * LIFECYCLE (ADR-139 D4): a single implicit state — the row exists <=> the
 * diagnosis is `RECORDED`. Its CLINICAL CONTENT (`code`, `code_system`,
 * `description`, `clinical_status`, `occurred_at`, `recorded_at`,
 * `authored_context` and every identity field) is IMMUTABLE after recording.
 * There is no status column beyond `clinical_status` (an open coded string,
 * behaviour-neutral — no lifecycle / authorization / validation branches on it,
 * D3). The ONLY permitted post-creation mutation is the ONE-TIME supersession
 * stamp ({@see markSupersededBy()}).
 *
 * CORRECTION (ADR-139 D4): {@see correcting()} builds a NEW `Diagnosis` carrying
 * the corrected coding / description / status plus `supersedes_diagnosis_id`
 * and a mandatory clinical `reason`; the persisting repository also stamps this
 * (the original) row. There is NO `UpdateDiagnosis` / `ResolveDiagnosis` /
 * `CancelDiagnosis` / `VoidDiagnosis`, NO in-place clinical mutation, NO
 * amendment table.
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
final class Diagnosis extends AggregateRoot
{
    /** @use HasFactory<DiagnosisFactory> */
    use HasFactory;

    /**
     * The authoring context of a diagnosis is HARDCODED (ADR-139 D13) — a
     * diagnosis is a clinician conclusion (§23 / §58 / §73); it is never a
     * command input and never `NURSING` / `PROCEDURE` / other in C.3.
     */
    public const AUTHORED_CONTEXT = 'CLINICIAN';

    /** ADR-139 D3 — the default open coded clinical status. */
    public const CLINICAL_STATUS_DEFAULT = 'WORKING';

    protected $table = 'clin_diagnoses';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'author_user_id', 'authored_context',
        'code', 'code_system', 'description', 'clinical_status',
        'supersedes_diagnosis_id', 'superseded_at', 'superseded_by_diagnosis_id', 'reason',
        'occurred_at', 'recorded_at',
    ];

    protected static function newFactory(): DiagnosisFactory
    {
        return DiagnosisFactory::new();
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
     * Record a new diagnosis (ADR-139). `$id` is client-minted (a UUIDv7) so the
     * `Auditable` `RecordDiagnosis` command names its target before the row
     * exists. Patient eligibility, the encounter-state gate and patient /
     * encounter consistency are the handler's responsibility (via `PatientApi` /
     * `EncounterApi`); `$facilityId` is the encounter context's facility
     * (ADR-135). `$occurredAt` is the clinical time the conclusion was reached
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
        DiagnosisCode $coding,
        string $description,
        string $clinicalStatus,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $description = self::assertDescription($description);
        $clinicalStatus = self::assertClinicalStatus($clinicalStatus);

        $diagnosis = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'author_user_id' => $authorUserId,
            'authored_context' => self::AUTHORED_CONTEXT,
            'code' => $coding->code,
            'code_system' => $coding->codeSystem,
            'description' => $description,
            'clinical_status' => $clinicalStatus,
            'supersedes_diagnosis_id' => null,
            'superseded_at' => null,
            'superseded_by_diagnosis_id' => null,
            'reason' => null,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $diagnosis->recordThat(new DiagnosisRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            diagnosisId: $id,
            diagnosisCodeSystem: $coding->codeSystem,
            clinicalStatus: $clinicalStatus,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $diagnosis;
    }

    /**
     * Build the NEW diagnosis that corrects (supersedes) `$original`
     * (ADR-139 D4). The context (tenant / facility / patient / encounter) is
     * copied from the original — a correction restates the SAME diagnostic
     * conclusion, so it is not re-gated against the encounter (the ADR-135
     * post-origination principle; the C.2 precedent). `$reason` is mandatory and
     * is CLINICAL data stored on this new row (never on the audit, never on the
     * event).
     *
     * The caller (`CorrectDiagnosisHandler`) has already loaded `$original`
     * (RLS-scoped) and asserted it is not already superseded.
     */
    public static function correcting(
        string $id,
        self $original,
        string $authorUserId,
        DiagnosisCode $coding,
        string $description,
        string $clinicalStatus,
        string $reason,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $description = self::assertDescription($description);
        $clinicalStatus = self::assertClinicalStatus($clinicalStatus);

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A correction reason is required (ADR-139 D4).');
        }

        $diagnosis = new self([
            'id' => $id,
            'tenant_id' => (string) $original->tenant_id,
            'facility_id' => (string) $original->facility_id,
            'patient_id' => (string) $original->patient_id,
            'encounter_id' => (string) $original->encounter_id,
            'author_user_id' => $authorUserId,
            'authored_context' => self::AUTHORED_CONTEXT,
            'code' => $coding->code,
            'code_system' => $coding->codeSystem,
            'description' => $description,
            'clinical_status' => $clinicalStatus,
            'supersedes_diagnosis_id' => (string) $original->id,
            'superseded_at' => null,
            'superseded_by_diagnosis_id' => null,
            'reason' => trim($reason),
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $diagnosis->recordThat(new DiagnosisCorrected(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $original->tenant_id,
            facilityId: (string) $original->facility_id,
            patientId: (string) $original->patient_id,
            encounterId: (string) $original->encounter_id,
            diagnosisId: $id,
            supersedesDiagnosisId: (string) $original->id,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $diagnosis;
    }

    /**
     * The ONE-TIME supersession stamp (ADR-139 D4). Sets ONLY `superseded_at`
     * (server `now()`) and `superseded_by_diagnosis_id`; the coding /
     * description / status and every other clinical field stay immutable.
     * Records NO event (the new diagnosis records `DiagnosisCorrected`). A
     * second call is a bug and is rejected.
     */
    public function markSupersededBy(string $newDiagnosisId): void
    {
        if ($this->superseded_at !== null) {
            throw DiagnosisAlreadySuperseded::for(
                (string) $this->id,
                (string) $this->superseded_by_diagnosis_id,
            );
        }

        $this->superseded_at = CarbonImmutable::now();
        $this->superseded_by_diagnosis_id = $newDiagnosisId;
    }

    /** This diagnosis has been corrected by a later (superseding) diagnosis. */
    public function isSuperseded(): bool
    {
        return $this->superseded_at !== null;
    }

    /** This diagnosis is itself a correction of an earlier diagnosis. */
    public function isCorrection(): bool
    {
        return $this->supersedes_diagnosis_id !== null;
    }

    private static function assertDescription(string $description): string
    {
        if (trim($description) === '') {
            throw new InvalidArgumentException('Diagnosis::$description must not be blank (ADR-139 D7).');
        }

        return trim($description);
    }

    private static function assertClinicalStatus(string $clinicalStatus): string
    {
        if (trim($clinicalStatus) === '') {
            throw new InvalidArgumentException('Diagnosis::$clinicalStatus must not be blank (ADR-139 D3).');
        }

        return trim($clinicalStatus);
    }
}
