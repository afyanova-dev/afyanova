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
 * Resolve an allergy — `clinical_status ACTIVE → RESOLVED`
 * (12-clinical-domain.md §31, §75; ADR-132, ADR-141 D-K / D-D).
 *
 * The allergy was clinically real but is no longer active (outgrown;
 * desensitised). Sets `clinical_status` and `resolved_at` (set once — never
 * rewritten); `verification_status` is unchanged. A non-`ACTIVE` allergy is
 * rejected in the aggregate (`IllegalAllergyStatusTransition`, 409 — not
 * idempotent, no unresolve). No history row is written (ADR-141 D-J). Emits one
 * `AllergyResolved` event and one `clinical.allergies.resolved` audit row
 * (`metadata = []`).
 *
 * `$resolvedAt` is the clinical resolution time (ISO-8601, optional — defaults
 * to server `now()`); never in the future. When it is more than
 * {@see BACKDATE_REASON_THRESHOLD_HOURS} hours earlier than now, `$reason` is
 * REQUIRED (ADR-132 — the > 24 h backdate rule applies to `resolved_at`,
 * ADR-141 D-H); it is written to the AUDIT row only, never to a `clin_` column.
 *
 * Per-subject authorization: the bus loads the allergy and checks
 * `AllergyPolicy::resolve` (the `clinical.allergies.record` key at the allergy's
 * ORIGINATING facility — ADR-141 D-K).
 */
final readonly class ResolveAllergy implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated documentation must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $allergyId,
        public string $actorId,
        public ?string $resolvedAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['allergyId' => $allergyId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ResolveAllergy::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($resolvedAt !== null) {
            try {
                $resolved = CarbonImmutable::parse($resolvedAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('ResolveAllergy::$resolvedAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($resolved->greaterThan($now)) {
                throw new InvalidArgumentException('ResolveAllergy::$resolvedAt must not be in the future (12 §96, ADR-132).');
            }

            if ($resolved->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'ResolveAllergy::$reason is required when $resolvedAt is more than %d hours in the past.',
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
        return $this->allergyId; // per-subject — the bus loads the allergy for the `resolve` policy check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.allergies.resolved',
            actorId: $this->actorId,
            targetType: 'allergy',
            targetId: $this->allergyId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
