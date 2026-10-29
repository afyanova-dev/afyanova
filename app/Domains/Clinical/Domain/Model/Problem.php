<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\ProblemAdded;
use App\Domains\Clinical\Contracts\Event\ProblemResolved;
use App\Domains\Clinical\Contracts\Event\ProblemUpdated;
use App\Domains\Clinical\Database\Factories\ProblemFactory;
use App\Domains\Clinical\Domain\Exception\IllegalProblemStatusTransition;
use App\Domains\Clinical\Domain\ValueObject\ProblemChangeType;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Domains\Clinical\Domain\ValueObject\ProblemStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One longitudinal problem-list entry for one patient — an
 * encounter-originated clinical concern that is tracked over time and
 * eventually resolved (12-clinical-domain.md §25–§27, §74; ADR-131, ADR-132,
 * ADR-135, ADR-140).
 *
 * A partitioned aggregate (ADR-131) — its own table `clin_problems`, not part
 * of `ClinicalNote`, not a `Diagnosis`, no encounter-level problem-list
 * aggregate. It owns ONLY the current problem state plus its append-only
 * `clin_problem_history` snapshots.
 *
 * ORIGIN (ADR-140 D-A / D-B): `encounter_id` and `facility_id` are the
 * ORIGINATING encounter / facility — mandatory and IMMUTABLE for life.
 * `facility_id` is the authorization / originating-facility-visibility anchor
 * and is taken from the encounter context, never client input (Invariant 12).
 * The problem's lifecycle may continue after that encounter closes —
 * "longitudinal" means the ENTRY persists, NOT that it is encounterless.
 *
 * LIFECYCLE (ADR-140 D-D / D-E): STABLE AGGREGATE IDENTITY + BOUNDED MUTATION +
 * APPEND-ONLY HISTORY — deliberately NOT the C.3 Diagnosis superseding-snapshot
 * model (C.4 introduces no global Clinical lifecycle or correction rule).
 * `status` is a closed two-value set `ACTIVE | RESOLVED`; the ONLY transition
 * is `ACTIVE → RESOLVED` (via {@see resolve()}); `status` is never directly
 * writable and there is no reactivation. The mutable clinical fields
 * (`description`, `code`, `code_system`, `onset_date`) are revised in place by
 * {@see revise()}, which first captures the complete pre-change state as a
 * {@see ProblemHistoryEntry}. `UpdateProblem` is status-agnostic — it may
 * correct a `RESOLVED` problem's content (ADR-140 clarification lock,
 * Decision 1) — but it never changes `status`, `resolved_at`, `facility_id`,
 * `encounter_id`, `source_diagnosis_id`, `occurred_at`, `recorded_at`,
 * `authored_context` or the identity fields.
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
 * @facility    required — the ORIGINATING facility (ADR-140 D-B); from the
 *              encounter context (ADR-135), never client input (Invariant 12).
 *
 * @property string $status ProblemStatus value (ACTIVE | RESOLVED)
 * @property CarbonImmutable|null $onset_date
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 */
final class Problem extends AggregateRoot
{
    /** @use HasFactory<ProblemFactory> */
    use HasFactory;

    /**
     * The authoring context of a problem is HARDCODED (ADR-140 D-Q) — a
     * problem-list entry is a clinician conclusion (§58 / §73); it is never a
     * command input and never `NURSING` / `PROCEDURE` / other in C.4.
     */
    public const AUTHORED_CONTEXT = 'CLINICIAN';

    protected $table = 'clin_problems';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'author_user_id', 'authored_context',
        'code', 'code_system', 'description', 'status',
        'onset_date', 'resolved_at', 'source_diagnosis_id',
        'occurred_at', 'recorded_at',
    ];

    /**
     * Pre-change history rows recorded in-memory by {@see revise()}, drained and
     * persisted by `EloquentProblemRepository::save()` (the
     * `ClinicalNoteAmendment` child-persist precedent).
     *
     * @var list<ProblemHistoryEntry>
     */
    private array $pendingHistory = [];

    protected static function newFactory(): ProblemFactory
    {
        return ProblemFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onset_date' => 'immutable_date',
            'resolved_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * Add a new problem to the patient's problem list, `ACTIVE` (ADR-140 D-A …
     * D-J). `$id` is client-minted (a UUIDv7) so the `Auditable` `AddProblem`
     * command names its target before the row exists (the `RecordDiagnosis`
     * precedent). Patient eligibility, the encounter-state gate, patient /
     * encounter consistency and the optional `sourceDiagnosisId` provenance
     * check are the handler's responsibility (via `PatientApi` / `EncounterApi`
     * / `DiagnosisReader`); `$facilityId` is the encounter context's facility
     * (ADR-140 D-B).
     *
     * `$occurredAt` is the clinical time the problem was added to the list (the
     * ADR-132 event time — never future, validated on the command; a > 24 h
     * backdate carries an audit `reason`). `$recordedAt` is server write time.
     * `$onsetDate` (nullable) is when the condition began; `$sourceDiagnosisId`
     * (nullable) is provenance only.
     */
    public static function add(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $authorUserId,
        ProblemCode $coding,
        string $description,
        ?CarbonImmutable $onsetDate,
        ?string $sourceDiagnosisId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $description = self::assertDescription($description);

        $problem = new self([
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
            'status' => ProblemStatus::ACTIVE,
            'onset_date' => $onsetDate,
            'resolved_at' => null,
            'source_diagnosis_id' => $sourceDiagnosisId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $problem->recordThat(new ProblemAdded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            problemId: $id,
            problemCodeSystem: $coding->codeSystem,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $problem;
    }

    /**
     * Revise this problem's clinical content in place (ADR-140 D-J). Captures
     * the COMPLETE pre-change state as an append-only {@see ProblemHistoryEntry}
     * BEFORE applying the new values, so nothing is lost. `$coding` /
     * `$description` / `$onsetDate` are the NEW complete state of the four
     * mutable fields (the `CorrectDiagnosis` full-values precedent — ADR-140
     * D-J "apply the new state"). Status-agnostic: legal on an `ACTIVE` or a
     * `RESOLVED` problem (Decision 1) — it never touches `status`,
     * `resolved_at`, `occurred_at`, `recorded_at`, `source_diagnosis_id`,
     * `facility_id`, `encounter_id` or identity.
     *
     * `$reason` is REQUIRED (non-blank) for `CORRECTION` and null for
     * `REVISION` — validated on the command. It is CLINICAL data stored on the
     * history row only (never on the audit, never on the event — ADR-140
     * D-N / D-M). `$authorUserId` / `$changeOccurredAt` / `$changeRecordedAt`
     * describe THIS change.
     */
    public function revise(
        string $changeType,
        ProblemCode $coding,
        string $description,
        ?CarbonImmutable $onsetDate,
        ?string $reason,
        string $authorUserId,
        CarbonImmutable $changeOccurredAt,
        CarbonImmutable $changeRecordedAt,
    ): void {
        $changeType = ProblemChangeType::assert($changeType);
        $description = self::assertDescription($description);

        $this->pendingHistory[] = ProblemHistoryEntry::fromPreChangeState(
            problem: $this,
            changeType: $changeType,
            reason: $reason,
            authorUserId: $authorUserId,
            occurredAt: $changeOccurredAt,
            recordedAt: $changeRecordedAt,
        );

        $this->code = $coding->code;
        $this->code_system = $coding->codeSystem;
        $this->description = $description;
        $this->onset_date = $onsetDate;

        $this->recordThat(new ProblemUpdated(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            problemId: (string) $this->id,
            changeType: $changeType,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `ACTIVE → RESOLVED` (ADR-140 D-E) — the ONLY lifecycle transition. Sets
     * `status` and `resolved_at` (server-supplied clinical resolution time, set
     * ONCE — never rewritten). A `RESOLVED` problem is rejected
     * (`IllegalProblemStatusTransition`, 409 — not idempotent, no
     * reactivation). Records NO history row (ADR-140 D-P).
     *
     * `$resolvedAt` is the clinical resolution time (never future, validated on
     * the command; a > 24 h backdate carries an audit `reason` — ADR-132).
     */
    public function resolve(CarbonImmutable $resolvedAt): void
    {
        if ($this->status !== ProblemStatus::ACTIVE) {
            throw IllegalProblemStatusTransition::resolve((string) $this->status);
        }

        $this->status = ProblemStatus::RESOLVED;
        $this->resolved_at = $resolvedAt;

        $this->recordThat(new ProblemResolved(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            problemId: (string) $this->id,
            resolvedAt: (string) $resolvedAt->toISOString(),
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /** This problem is on the current problem list. */
    public function isActive(): bool
    {
        return $this->status === ProblemStatus::ACTIVE;
    }

    /** This problem has been resolved. */
    public function isResolved(): bool
    {
        return $this->status === ProblemStatus::RESOLVED;
    }

    /** The current coding as a value object. */
    public function coding(): ProblemCode
    {
        return ProblemCode::of($this->code, $this->code_system);
    }

    /**
     * The pre-change history rows recorded since the last drain, clearing the
     * buffer. `EloquentProblemRepository::save()` calls this after persisting
     * the problem and persists each row in the same transaction.
     *
     * @return list<ProblemHistoryEntry>
     */
    public function releasePendingHistory(): array
    {
        $history = $this->pendingHistory;
        $this->pendingHistory = [];

        return $history;
    }

    private static function assertDescription(string $description): string
    {
        if (trim($description) === '') {
            throw new InvalidArgumentException('Problem::$description must not be blank (ADR-140 D-H).');
        }

        return trim($description);
    }
}
