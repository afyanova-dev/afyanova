<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Transition an allergy assertion's VERIFICATION status (12-clinical-domain.md
 * §31, §32; ADR-141 D-K / D-D). One parameterised command, two outcomes:
 *
 *   `CONFIRMED` — `SUSPECTED → CONFIRMED`; `clinical_status` unchanged;
 *                 `$reason` must be null / absent.
 *   `REFUTED`   — `{SUSPECTED | CONFIRMED} → REFUTED` AND ATOMICALLY
 *                 `clinical_status {ACTIVE | RESOLVED} → INACTIVE` (the C.5
 *                 biconditional `REFUTED ⇔ INACTIVE`, ADR-141 D-D); `$reason`
 *                 is REQUIRED (non-blank clinical rationale). `REFUTED` is
 *                 terminal — no de-refutation.
 *
 * The refutation `$reason` is CLINICAL data that lives on the
 * `clin_allergy_history` pre-change snapshot ONLY — never on the audit, never
 * on the event (ADR-141 D-Q / D-R). `VerifyAllergy` takes NO caller-supplied
 * change timestamp — the history row's `occurred_at` / `recorded_at` are server
 * `now()` (ADR-141 D-H), so this command's audit `reason` is ALWAYS null.
 *
 * Not idempotent: repeated `VerifyAllergy(CONFIRMED)` on a `CONFIRMED` allergy,
 * or `VerifyAllergy(REFUTED)` on a `REFUTED` allergy, raises a domain exception
 * (`IllegalAllergyStatusTransition`, 409).
 *
 * Per-subject authorization: the bus loads the allergy and checks
 * `AllergyPolicy::verify` (the `clinical.allergies.record` key at the allergy's
 * ORIGINATING facility — ADR-141 D-K).
 */
final readonly class VerifyAllergy implements Auditable, AuthorizesAgainst
{
    /** AllergyVerificationStatus value — `CONFIRMED` | `REFUTED` (ADR-141 D-K). */
    public string $verificationStatus;

    public function __construct(
        public string $allergyId,
        public string $actorId,
        string $verificationStatus,
        public ?string $reason = null,
    ) {
        foreach (['allergyId' => $allergyId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("VerifyAllergy::\${$name} must be a UUID (IC §3).");
            }
        }

        $this->verificationStatus = AllergyVerificationStatus::assertVerifiable($verificationStatus);

        if ($this->verificationStatus === AllergyVerificationStatus::REFUTED && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('VerifyAllergy::$reason is required when $verificationStatus is REFUTED.');
        }

        if ($this->verificationStatus === AllergyVerificationStatus::CONFIRMED && $reason !== null && trim($reason) !== '') {
            throw new InvalidArgumentException('VerifyAllergy::$reason must be null when $verificationStatus is CONFIRMED.');
        }
    }

    /** The `REFUTED` rationale, trimmed — clinical data for the history row (never the audit / event). */
    public function refutationReason(): ?string
    {
        if ($this->verificationStatus !== AllergyVerificationStatus::REFUTED) {
            return null;
        }

        return $this->reason !== null && trim($this->reason) !== '' ? trim($this->reason) : null;
    }

    public function policyAbility(): string
    {
        return 'verify';
    }

    public function subjectId(): string
    {
        return $this->allergyId; // per-subject — the bus loads the allergy for the `verify` policy check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.allergies.verified',
            actorId: $this->actorId,
            targetType: 'allergy',
            targetId: $this->allergyId,
            reason: null, // the REFUTED rationale is clinical data on the clin_allergy_history row
        );
    }
}
