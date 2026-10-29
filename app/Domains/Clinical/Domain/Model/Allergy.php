<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Model;

use App\Domains\Clinical\Contracts\Event\AllergyRecorded;
use App\Domains\Clinical\Contracts\Event\AllergyResolved;
use App\Domains\Clinical\Contracts\Event\AllergyUpdated;
use App\Domains\Clinical\Contracts\Event\AllergyVerified;
use App\Domains\Clinical\Database\Factories\AllergyFactory;
use App\Domains\Clinical\Domain\Exception\IllegalAllergyStatusTransition;
use App\Domains\Clinical\Domain\ValueObject\AllergyChangeType;
use App\Domains\Clinical\Domain\ValueObject\AllergyStatus;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Domain\AggregateRoot;
use App\Shared\Support\Causation;
use App\Shared\Support\CorrelationId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One encounter-originated longitudinal immune-mediated-hypersensitivity
 * assertion for one patient — a PURE ALLERGY (12-clinical-domain.md §30–§33,
 * §75; ADR-131, ADR-132, ADR-135, ADR-141). It does NOT model intolerance,
 * pharmacologic side effects, adverse drug reactions, adverse events,
 * contraindications or a unified `AllergyIntolerance` concept (ADR-141 D-A).
 *
 * A partitioned aggregate (ADR-131) — its own table `clin_allergies`, not part
 * of `ClinicalNote`, not a `Diagnosis`, not a `Problem`, no encounter-level
 * allergy-list aggregate. It owns ONLY the current allergy state plus its
 * append-only `clin_allergy_history` snapshots.
 *
 * ORIGIN (ADR-141 D-B): `encounter_id` and `facility_id` are the ORIGINATING
 * encounter / facility — mandatory and IMMUTABLE for life. `facility_id` is the
 * WRITE-authorization anchor and is taken from the encounter context, never
 * client input (Invariant 12). The lifecycle may continue after that encounter
 * closes — "longitudinal" means the ENTRY persists, NOT that it is
 * encounterless. There is NO null-`encounter_id` allergy in PHASE 6 (ADR-135
 * unamended).
 *
 * TWO-AXIS STATUS (ADR-141 D-D) with the aggregate-enforced C.5 BICONDITIONAL:
 *
 *     verification_status = REFUTED   <=>   clinical_status = INACTIVE
 *
 *   clinical_status     : ACTIVE (default) | RESOLVED | INACTIVE
 *   verification_status : SUSPECTED (default) | CONFIRMED | REFUTED
 *
 * Exactly five reachable combinations — (ACTIVE, SUSPECTED) (ACTIVE, CONFIRMED)
 * (RESOLVED, SUSPECTED) (RESOLVED, CONFIRMED) (INACTIVE, REFUTED) — enforced by
 * the four transition methods below. `INACTIVE` has EXACTLY ONE entry path,
 * {@see verify()} with `REFUTED`; there is no `InactivateAllergy` and no
 * reactivation. `RESOLVED` and `INACTIVE` are terminal.
 *
 * LIFECYCLE (ADR-141 D-J): STABLE AGGREGATE IDENTITY + BOUNDED MUTATION +
 * APPEND-ONLY HISTORY — the C.4 model reused as a C.5-SPECIFIC decision (NOT a
 * global Clinical rule; ADR-134 / ADR-138 / ADR-139 unchanged; NOT the C.3
 * superseding-record model). {@see revise()} (content) and {@see verify()}
 * each capture the complete pre-change state as an {@see AllergyHistoryEntry}
 * BEFORE applying the change; {@see resolve()} writes NO history row (the
 * ADR-140 D-P `ResolveProblem` precedent).
 *
 * `casts()` (the method) layers the date casts over `AggregateRoot`'s own
 * `$casts`; Larastan reads the property, so the effective shape is pinned in
 * `@property` tags.
 *
 * @domain      Clinical
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    required — the ORIGINATING facility (ADR-141 D-B); from the
 *              encounter context (ADR-135), never client input (Invariant 12).
 *
 * @property string $clinical_status AllergyStatus value (ACTIVE | RESOLVED | INACTIVE)
 * @property string $verification_status AllergyVerificationStatus value (SUSPECTED | CONFIRMED | REFUTED)
 * @property CarbonImmutable|null $onset_date
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 */
final class Allergy extends AggregateRoot
{
    /** @use HasFactory<AllergyFactory> */
    use HasFactory;

    public const AUTHORED_CONTEXT_CLINICIAN = 'CLINICIAN';

    public const AUTHORED_CONTEXT_NURSING = 'NURSING';

    public const AUTHORED_CONTEXT_DEFAULT = self::AUTHORED_CONTEXT_CLINICIAN;

    /**
     * The CLOSED C.5 set for `authored_context` (ADR-141 D-I — the ADR-138 D8
     * Vitals precedent, NOT the ADR-139 D13 / ADR-140 D-Q hardcode, because an
     * allergy record is a documentation act frequently performed at nursing
     * intake). It is caller-supplied and set-membership-validated ONLY — never
     * an authorization input, never re-checked against the actor's identity or
     * role, no `IdentityApi` call.
     *
     * @var list<string>
     */
    public const AUTHORED_CONTEXTS = [self::AUTHORED_CONTEXT_CLINICIAN, self::AUTHORED_CONTEXT_NURSING];

    /**
     * The currently documented `category` values (ADR-141 D-F). These are
     * DOCUMENTED values for readers and tooling — NOT a DB-enforced closed enum
     * and NOT behaviour-driving. An unknown / future coded value is accepted
     * provided it is a non-blank trimmed string within the length bound.
     *
     * @var list<string>
     */
    public const CATEGORIES = ['DRUG', 'FOOD', 'ENVIRONMENT', 'BIOLOGIC', 'OTHER'];

    /**
     * The currently documented `severity` values (ADR-141 D-G) — behaviour-
     * neutral, open, not enforced.
     *
     * @var list<string>
     */
    public const SEVERITIES = ['MILD', 'MODERATE', 'SEVERE'];

    private const MAX_CATEGORY_LENGTH = 100;

    private const MAX_SEVERITY_LENGTH = 100;

    protected $table = 'clin_allergies';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id', 'facility_id', 'patient_id', 'encounter_id',
        'author_user_id', 'authored_context',
        'category', 'substance_name', 'substance_code', 'substance_code_system',
        'reaction_description', 'severity',
        'clinical_status', 'verification_status',
        'onset_date', 'resolved_at',
        'occurred_at', 'recorded_at',
    ];

    /**
     * Pre-change history rows recorded in-memory by {@see revise()} /
     * {@see verify()}, drained and persisted by
     * `EloquentAllergyRepository::save()` in the same transaction (the
     * `ProblemHistoryEntry` precedent).
     *
     * @var list<AllergyHistoryEntry>
     */
    private array $pendingHistory = [];

    protected static function newFactory(): AllergyFactory
    {
        return AllergyFactory::new();
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
     * Record a new allergy on the patient's safety record — `clinical_status`
     * `ACTIVE`, `verification_status` `SUSPECTED` (default) or `CONFIRMED`
     * (ADR-141 D-K / D-D). An allergy is NEVER recorded as already `REFUTED`,
     * `INACTIVE` or `RESOLVED`.
     *
     * `$id` is client-minted (a UUIDv7) so the `Auditable` `RecordAllergy`
     * command names its target before the row exists (the `RecordDiagnosis` /
     * `AddProblem` precedent). Patient eligibility, the encounter-state gate and
     * patient / encounter consistency are the handler's responsibility (via
     * `PatientApi` / `EncounterApi`); `$facilityId` is the encounter context's
     * facility (ADR-141 D-B), never client input.
     *
     * `$occurredAt` is the clinical time the allergy was entered on the record
     * (the ADR-132 event time — never future, validated on the command; a
     * > 24 h backdate carries an audit `reason`). `$recordedAt` is server write
     * time. `$onsetDate` (nullable) is when the allergy / first reaction began.
     */
    public static function record(
        string $id,
        string $tenantId,
        string $facilityId,
        string $patientId,
        string $encounterId,
        string $authorUserId,
        string $authoredContext,
        string $category,
        AllergySubstance $substance,
        ?string $reactionDescription,
        ?string $severity,
        string $verificationStatus,
        ?CarbonImmutable $onsetDate,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        $authoredContext = self::assertAuthoredContext($authoredContext);
        $category = self::assertCategory($category);
        $severity = self::normaliseSeverity($severity);
        $reactionDescription = self::normaliseReactionDescription($reactionDescription);
        $verificationStatus = AllergyVerificationStatus::assertRecordable($verificationStatus);

        $allergy = new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'patient_id' => $patientId,
            'encounter_id' => $encounterId,
            'author_user_id' => $authorUserId,
            'authored_context' => $authoredContext,
            'category' => $category,
            'substance_name' => $substance->name,
            'substance_code' => $substance->code,
            'substance_code_system' => $substance->codeSystem,
            'reaction_description' => $reactionDescription,
            'severity' => $severity,
            'clinical_status' => AllergyStatus::ACTIVE,
            'verification_status' => $verificationStatus,
            'onset_date' => $onsetDate,
            'resolved_at' => null,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);

        $allergy->recordThat(new AllergyRecorded(
            eventId: (string) Str::uuid7(),
            tenantId: $tenantId,
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            allergyId: $id,
            category: $category,
            clinicalStatus: AllergyStatus::ACTIVE,
            verificationStatus: $verificationStatus,
            substanceCodeSystem: $substance->codeSystem,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));

        return $allergy;
    }

    /**
     * Revise or correct this allergy's clinical CONTENT in place (ADR-141 D-K).
     * Captures the COMPLETE pre-change state as an append-only
     * {@see AllergyHistoryEntry} BEFORE applying the new values. `$substance` /
     * `$category` / `$reactionDescription` / `$severity` / `$onsetDate` are the
     * NEW COMPLETE state of the mutable content fields.
     *
     * STATUS-AGNOSTIC (the ADR-140 `UpdateProblem` precedent) — legal on an
     * `ACTIVE`, `RESOLVED` or `INACTIVE` allergy (a typo may always be fixed);
     * it NEVER touches `clinical_status`, `verification_status`, `resolved_at`,
     * `occurred_at`, `recorded_at`, `facility_id`, `encounter_id`,
     * `author_user_id`, `authored_context` or identity, and NEVER reactivates.
     *
     * `$reason` is REQUIRED (non-blank) for `CORRECTION` and null for
     * `REVISION` — validated on the command AND here. It is CLINICAL data stored
     * on the history row ONLY (never on the audit, never on the event — ADR-141
     * D-Q / D-R). `$authorUserId` / `$changeOccurredAt` / `$changeRecordedAt`
     * describe THIS change (server time — ADR-141 D-H).
     */
    public function revise(
        string $changeType,
        AllergySubstance $substance,
        string $category,
        ?string $reactionDescription,
        ?string $severity,
        ?CarbonImmutable $onsetDate,
        ?string $reason,
        string $authorUserId,
        CarbonImmutable $changeOccurredAt,
        CarbonImmutable $changeRecordedAt,
    ): void {
        $changeType = AllergyChangeType::assert($changeType);
        $category = self::assertCategory($category);
        $severity = self::normaliseSeverity($severity);
        $reactionDescription = self::normaliseReactionDescription($reactionDescription);
        $reason = $changeType === AllergyChangeType::CORRECTION
            ? self::assertMutationReason($reason, 'UpdateAllergy CORRECTION')
            : null;

        $this->pendingHistory[] = AllergyHistoryEntry::fromPreChangeState(
            allergy: $this,
            changeKind: AllergyHistoryEntry::CHANGE_KIND_CONTENT,
            changeType: $changeType,
            reason: $reason,
            authorUserId: $authorUserId,
            occurredAt: $changeOccurredAt,
            recordedAt: $changeRecordedAt,
        );

        $this->category = $category;
        $this->substance_name = $substance->name;
        $this->substance_code = $substance->code;
        $this->substance_code_system = $substance->codeSystem;
        $this->reaction_description = $reactionDescription;
        $this->severity = $severity;
        $this->onset_date = $onsetDate;

        $this->recordThat(new AllergyUpdated(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            allergyId: (string) $this->id,
            changeType: $changeType,
            category: $category,
            clinicalStatus: (string) $this->clinical_status,
            verificationStatus: (string) $this->verification_status,
            substanceCodeSystem: $substance->codeSystem,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * Transition the VERIFICATION axis (ADR-141 D-K / D-D). One parameterised
     * command, two outcomes:
     *
     *   CONFIRMED : SUSPECTED -> CONFIRMED. `clinical_status` unchanged.
     *               `$reason` is ignored (a confirmation carries no clinical
     *               rationale). Guard: must currently be SUSPECTED.
     *   REFUTED   : {SUSPECTED | CONFIRMED} -> REFUTED  AND ATOMICALLY
     *               `clinical_status` {ACTIVE | RESOLVED} -> INACTIVE
     *               (the C.5 biconditional). `$reason` is MANDATORY (non-blank
     *               clinical rationale — history row only). Guard: must not
     *               already be REFUTED (terminal).
     *
     * Always writes one append-only {@see AllergyHistoryEntry} capturing the
     * pre-change verification AND clinical status. NOT idempotent.
     */
    public function verify(
        string $verificationStatus,
        ?string $reason,
        string $authorUserId,
        CarbonImmutable $changeOccurredAt,
        CarbonImmutable $changeRecordedAt,
    ): void {
        $verificationStatus = AllergyVerificationStatus::assertVerifiable($verificationStatus);

        if ($verificationStatus === AllergyVerificationStatus::CONFIRMED) {
            if ($this->verification_status !== AllergyVerificationStatus::SUSPECTED) {
                throw IllegalAllergyStatusTransition::confirmVerification((string) $this->verification_status);
            }

            $reason = null;
            $newClinicalStatus = (string) $this->clinical_status;
        } else { // REFUTED
            if ($this->verification_status === AllergyVerificationStatus::REFUTED) {
                throw IllegalAllergyStatusTransition::refuteVerification((string) $this->verification_status);
            }

            $reason = self::assertMutationReason($reason, 'VerifyAllergy(REFUTED)');
            $newClinicalStatus = AllergyStatus::INACTIVE;
        }

        $this->pendingHistory[] = AllergyHistoryEntry::fromPreChangeState(
            allergy: $this,
            changeKind: AllergyHistoryEntry::CHANGE_KIND_VERIFICATION,
            changeType: $verificationStatus,
            reason: $reason,
            authorUserId: $authorUserId,
            occurredAt: $changeOccurredAt,
            recordedAt: $changeRecordedAt,
        );

        $this->verification_status = $verificationStatus;
        $this->clinical_status = $newClinicalStatus;

        $this->recordThat(new AllergyVerified(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            allergyId: (string) $this->id,
            verificationStatus: $verificationStatus,
            clinicalStatus: $newClinicalStatus,
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /**
     * `clinical_status ACTIVE -> RESOLVED` (ADR-141 D-K / D-D) — the allergy was
     * clinically real but is no longer active. Sets `clinical_status` and
     * `resolved_at` (server-supplied clinical resolution time, set ONCE — never
     * rewritten); `verification_status` is unchanged. A non-`ACTIVE` allergy is
     * rejected (`IllegalAllergyStatusTransition`, 409 — not idempotent, no
     * unresolve). Records NO history row (ADR-141 D-J — the ADR-140 D-P
     * precedent).
     *
     * `$resolvedAt` is the clinical resolution time (never future, validated on
     * the command; a > 24 h backdate carries an audit `reason` — ADR-132).
     */
    public function resolve(CarbonImmutable $resolvedAt): void
    {
        if ($this->clinical_status !== AllergyStatus::ACTIVE) {
            throw IllegalAllergyStatusTransition::resolve((string) $this->clinical_status);
        }

        $this->clinical_status = AllergyStatus::RESOLVED;
        $this->resolved_at = $resolvedAt;

        $this->recordThat(new AllergyResolved(
            eventId: (string) Str::uuid7(),
            tenantId: (string) $this->tenant_id,
            facilityId: (string) $this->facility_id,
            patientId: (string) $this->patient_id,
            encounterId: (string) $this->encounter_id,
            allergyId: (string) $this->id,
            resolvedAt: (string) $resolvedAt->toISOString(),
            occurredAt: (string) now()->toISOString(),
            correlationId: CorrelationId::get(),
            causationId: Causation::get(),
        ));
    }

    /** This allergy is on the current safety list. */
    public function isActive(): bool
    {
        return $this->clinical_status === AllergyStatus::ACTIVE;
    }

    /** This allergy was real but is no longer active. */
    public function isResolved(): bool
    {
        return $this->clinical_status === AllergyStatus::RESOLVED;
    }

    /** This allergy assertion was disproven (always together with `verification_status = REFUTED`). */
    public function isInactive(): bool
    {
        return $this->clinical_status === AllergyStatus::INACTIVE;
    }

    /** The current substance as a value object. */
    public function substance(): AllergySubstance
    {
        return AllergySubstance::of(
            name: (string) $this->substance_name,
            code: $this->substance_code,
            codeSystem: $this->substance_code_system,
        );
    }

    /**
     * The pre-change history rows recorded since the last drain, clearing the
     * buffer. `EloquentAllergyRepository::save()` calls this after persisting
     * the allergy and persists each row in the same transaction.
     *
     * @return list<AllergyHistoryEntry>
     */
    public function releasePendingHistory(): array
    {
        $history = $this->pendingHistory;
        $this->pendingHistory = [];

        return $history;
    }

    private static function assertAuthoredContext(string $value): string
    {
        if (! in_array($value, self::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Allergy::$authored_context must be one of %s (ADR-141 D-I).',
                implode(' / ', self::AUTHORED_CONTEXTS),
            ));
        }

        return $value;
    }

    private static function assertCategory(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException('Allergy::$category must not be blank (ADR-141 D-F).');
        }

        if (mb_strlen($value) > self::MAX_CATEGORY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Allergy::$category must be at most %d characters (ADR-141 D-F).',
                self::MAX_CATEGORY_LENGTH,
            ));
        }

        return $value;
    }

    private static function normaliseSeverity(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (mb_strlen($value) > self::MAX_SEVERITY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Allergy::$severity must be at most %d characters (ADR-141 D-G).',
                self::MAX_SEVERITY_LENGTH,
            ));
        }

        return $value;
    }

    private static function normaliseReactionDescription(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private static function assertMutationReason(?string $reason, string $context): string
    {
        if ($reason === null || trim($reason) === '') {
            throw new InvalidArgumentException(sprintf(
                '%s requires a non-blank clinical reason (ADR-141 D-K / D-J).',
                $context,
            ));
        }

        return trim($reason);
    }
}
