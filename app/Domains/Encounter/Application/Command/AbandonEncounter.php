<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * `AbandonEncounter` (11-encounter-domain.md §19, §24; ADR-121:
 * `ARRIVED → LEFT_WITHOUT_BEING_SEEN | ACTIVE → ABANDONED`; ADR-130) — record
 * that a patient departed before consultation or mid-care without completing
 * the attendance. Terminal transition. Non-idempotent.
 *
 * Implements `AuthorizesAgainst` (policy ability `'abandon'`, subject ID is the
 * encounter UUID) and `Auditable` (action `'encounter.abandoned'`, target
 * `'encounter'`, empty metadata, no PHI).
 */
final readonly class AbandonEncounter implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $actorId,
    ) {
        if (! Str::isUuid($this->encounterId)) {
            throw new InvalidArgumentException("Invalid encounter UUID: [{$this->encounterId}].");
        }

        if (! Str::isUuid($this->actorId)) {
            throw new InvalidArgumentException("Invalid actor UUID: [{$this->actorId}].");
        }
    }

    public function policyAbility(): string
    {
        return 'abandon';
    }

    public function subjectId(): string
    {
        return $this->encounterId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.abandoned',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
            reason: null,
            metadata: [],
        );
    }
}
