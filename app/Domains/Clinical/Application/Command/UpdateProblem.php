<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\ProblemChangeType;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Revise or correct a longitudinal problem's clinical content IN PLACE
 * (12-clinical-domain.md §94; ADR-140 D-J).
 *
 * `UpdateProblem` mutates ONLY the four ratified mutable fields — `description`,
 * `code`, `code_system`, `onset_date` — and carries the NEW COMPLETE STATE of
 * those fields (the `CorrectDiagnosis` full-values precedent; ADR-140 D-J
 * "apply the new state"). It NEVER changes `status`, `resolved_at`,
 * `facility_id`, `encounter_id`, `source_diagnosis_id`, `occurred_at`,
 * `recorded_at`, `authored_context` or identity, and it never creates a new
 * `Problem`. It is STATUS-AGNOSTIC — legal on an `ACTIVE` or a `RESOLVED`
 * problem (ADR-140 clarification lock, Decision 1).
 *
 * `$changeType`:
 *   - `REVISION`   — an ordinary legitimate clinical modification; `$reason`
 *                    must be null / absent.
 *   - `CORRECTION` — correction of previously recorded information; `$reason`
 *                    is REQUIRED (non-blank). It is CLINICAL data that lives on
 *                    the `clin_problem_history` pre-change snapshot ONLY — never
 *                    on the audit, never on the event (ADR-140 D-N / D-M).
 *
 * Every successful update preserves the complete pre-change clinical state in
 * exactly one append-only `clin_problem_history` row, bumps `version`
 * (optimistic concurrency — a stale save is `ConcurrencyConflict` / 409), emits
 * one `ProblemUpdated` event and writes one `clinical.problems.updated` audit
 * row (`metadata = []`, `reason = null`).
 *
 * `$coding` is the validated {@see ProblemCode} value object (open `code` /
 * `code_system`, both-or-neither — ADR-140 D-G; MUTABLE, the pre-change value
 * is kept in history). `$onsetDate` (optional, `Y-m-d`) is the new onset date —
 * `null` clears it. Never in the future.
 *
 * Per-subject authorization: the bus loads the problem and checks
 * `ProblemPolicy::update` (the `clinical.problems.record` key at the problem's
 * ORIGINATING facility — ADR-140 D-K).
 */
final readonly class UpdateProblem implements Auditable, AuthorizesAgainst
{
    /** ProblemChangeType value — `REVISION` | `CORRECTION` (ADR-140 D-J). */
    public string $changeType;

    public function __construct(
        public string $problemId,
        public string $actorId,
        string $changeType,
        public ProblemCode $coding,
        public string $description,
        public ?string $onsetDate = null,
        public ?string $reason = null,
    ) {
        foreach (['problemId' => $problemId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("UpdateProblem::\${$name} must be a UUID (IC §3).");
            }
        }

        $this->changeType = ProblemChangeType::assert($changeType);

        if (trim($description) === '') {
            throw new InvalidArgumentException('UpdateProblem::$description must not be blank.');
        }

        if ($this->changeType === ProblemChangeType::CORRECTION && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('UpdateProblem::$reason is required when $changeType is CORRECTION.');
        }

        if ($onsetDate !== null) {
            try {
                $onset = CarbonImmutable::parse($onsetDate);
            } catch (Throwable) {
                throw new InvalidArgumentException('UpdateProblem::$onsetDate must be an ISO-8601 date or null.');
            }

            if ($onset->startOfDay()->greaterThan(CarbonImmutable::now()->startOfDay())) {
                throw new InvalidArgumentException('UpdateProblem::$onsetDate must not be in the future.');
            }
        }
    }

    /** The `CORRECTION` rationale, trimmed — clinical data for the history row (never the audit / event). */
    public function correctionReason(): ?string
    {
        if ($this->changeType !== ProblemChangeType::CORRECTION) {
            return null;
        }

        return $this->reason !== null && trim($this->reason) !== '' ? trim($this->reason) : null;
    }

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->problemId; // per-subject — the bus loads the problem for the `update` policy check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.problems.updated',
            actorId: $this->actorId,
            targetType: 'problem',
            targetId: $this->problemId,
            reason: null, // the correction rationale is clinical data on the clin_problem_history row
        );
    }
}
