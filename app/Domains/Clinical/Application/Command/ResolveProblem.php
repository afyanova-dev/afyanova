<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Resolve a longitudinal problem — `ACTIVE → RESOLVED`
 * (12-clinical-domain.md §74; ADR-132, ADR-140 D-E).
 *
 * The ONLY lifecycle transition C.4 defines. Sets `status` and `resolved_at`
 * (set once — never rewritten). A `RESOLVED` problem is rejected in the
 * aggregate (`IllegalProblemStatusTransition`, 409 — not idempotent, no
 * reactivation). No history row is written (ADR-140 D-P). Emits one
 * `ProblemResolved` event and one `clinical.problems.resolved` audit row
 * (`metadata = []`).
 *
 * `$resolvedAt` is the clinical resolution time (ISO-8601, optional — defaults
 * to server `now()`); never in the future. When it is more than
 * {@see BACKDATE_REASON_THRESHOLD_HOURS} hours earlier than now, `$reason` is
 * REQUIRED (ADR-132 — the > 24 h backdate rule applies to `resolved_at`, D-F);
 * it is written to the AUDIT row only, never to a `clin_` column.
 *
 * Per-subject authorization: the bus loads the problem and checks
 * `ProblemPolicy::resolve` (the `clinical.problems.record` key at the problem's
 * ORIGINATING facility — ADR-140 D-K).
 */
final readonly class ResolveProblem implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated documentation must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $problemId,
        public string $actorId,
        public ?string $resolvedAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['problemId' => $problemId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ResolveProblem::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($resolvedAt !== null) {
            try {
                $resolved = CarbonImmutable::parse($resolvedAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('ResolveProblem::$resolvedAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($resolved->greaterThan($now)) {
                throw new InvalidArgumentException('ResolveProblem::$resolvedAt must not be in the future (12 §96, ADR-132).');
            }

            if ($resolved->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'ResolveProblem::$reason is required when $resolvedAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'resolve';
    }

    public function subjectId(): string
    {
        return $this->problemId; // per-subject — the bus loads the problem for the `resolve` policy check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.problems.resolved',
            actorId: $this->actorId,
            targetType: 'problem',
            targetId: $this->problemId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
