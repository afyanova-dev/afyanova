<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * `CancelEncounter` (11-encounter-domain.md §19, §23, §74; ADR-121:
 * `PLANNED or ARRIVED → CANCELLED`; ADR-130) — cancel an encounter before
 * active care begins. Terminal state. Non-idempotent.
 *
 * Implements `AuthorizesAgainst` (policy ability `'cancel'`, subject ID is the
 * encounter UUID) and `Auditable` (action `'encounter.cancelled'`, target
 * `'encounter'`, empty metadata, no PHI).
 */
final readonly class CancelEncounter implements Auditable, AuthorizesAgainst
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
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->encounterId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'encounter.cancelled',
            actorId: $this->actorId,
            targetType: 'encounter',
            targetId: $this->encounterId,
            reason: null,
            metadata: [],
        );
    }
}
