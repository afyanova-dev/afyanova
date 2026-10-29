<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\AllergyChangeType;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Revise or correct an allergy assertion's clinical CONTENT in place
 * (12-clinical-domain.md §94; ADR-141 D-K / D-J).
 *
 * `UpdateAllergy` mutates ONLY the mutable content fields — `substance` (name /
 * code / code_system), `category`, `reaction_description`, `severity`,
 * `onset_date` — and carries the NEW COMPLETE STATE of those fields (the
 * `UpdateProblem` full-values precedent; ADR-141 D-K "apply the new state"). It
 * NEVER changes `clinical_status`, `verification_status`, `resolved_at`,
 * `facility_id`, `encounter_id`, `author_user_id`, `authored_context`,
 * `occurred_at`, `recorded_at` or identity, and it never reactivates. It is
 * STATUS-AGNOSTIC — legal on an `ACTIVE`, `RESOLVED` or `INACTIVE` allergy (a
 * typo may always be fixed).
 *
 * `$changeType`:
 *   - `REVISION`   — an ordinary legitimate clinical modification; `$reason`
 *                    must be null / absent.
 *   - `CORRECTION` — correction of previously recorded information; `$reason`
 *                    is REQUIRED (non-blank). It is CLINICAL data that lives on
 *                    the `clin_allergy_history` pre-change snapshot ONLY — never
 *                    on the audit, never on the event (ADR-141 D-Q / D-R).
 *
 * `UpdateAllergy` takes NO caller-supplied change timestamp — the
 * `clin_allergy_history` row's `occurred_at` / `recorded_at` are server `now()`
 * (the `UpdateProblemHandler` precedent; ADR-141 D-H), so this command's audit
 * `reason` is ALWAYS null.
 *
 * `$substance` is the validated {@see AllergySubstance} value object (open
 * code / code_system, both-or-neither — ADR-141 D-E; MUTABLE, the pre-change
 * value is kept in history). `$onsetDate` (optional, `Y-m-d`) is the new onset
 * date — `null` clears it. Never in the future.
 *
 * Per-subject authorization: the bus loads the allergy and checks
 * `AllergyPolicy::update` (the `clinical.allergies.record` key at the allergy's
 * ORIGINATING facility — ADR-141 D-K).
 */
final readonly class UpdateAllergy implements Auditable, AuthorizesAgainst
{
    /** AllergyChangeType value — `REVISION` | `CORRECTION` (ADR-141 D-K). */
    public string $changeType;

    public function __construct(
        public string $allergyId,
        public string $actorId,
        string $changeType,
        public string $category,
        public AllergySubstance $substance,
        public ?string $reactionDescription = null,
        public ?string $severity = null,
        public ?string $onsetDate = null,
        public ?string $reason = null,
    ) {
        foreach (['allergyId' => $allergyId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("UpdateAllergy::\${$name} must be a UUID (IC §3).");
            }
        }

        $this->changeType = AllergyChangeType::assert($changeType);

        if (trim($category) === '') {
            throw new InvalidArgumentException('UpdateAllergy::$category must not be blank.');
        }

        if ($this->changeType === AllergyChangeType::CORRECTION && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('UpdateAllergy::$reason is required when $changeType is CORRECTION.');
        }

        if ($this->changeType === AllergyChangeType::REVISION && $reason !== null && trim($reason) !== '') {
            throw new InvalidArgumentException('UpdateAllergy::$reason must be null when $changeType is REVISION.');
        }

        if ($onsetDate !== null) {
            try {
                $onset = CarbonImmutable::parse($onsetDate);
            } catch (Throwable) {
                throw new InvalidArgumentException('UpdateAllergy::$onsetDate must be an ISO-8601 date or null.');
            }

            if ($onset->startOfDay()->greaterThan(CarbonImmutable::now()->startOfDay())) {
                throw new InvalidArgumentException('UpdateAllergy::$onsetDate must not be in the future.');
            }
        }
    }

    /** The `CORRECTION` rationale, trimmed — clinical data for the history row (never the audit / event). */
    public function correctionReason(): ?string
    {
        if ($this->changeType !== AllergyChangeType::CORRECTION) {
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
        return $this->allergyId; // per-subject — the bus loads the allergy for the `update` policy check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.allergies.updated',
            actorId: $this->actorId,
            targetType: 'allergy',
            targetId: $this->allergyId,
            reason: null, // the CORRECTION rationale is clinical data on the clin_allergy_history row
        );
    }
}
