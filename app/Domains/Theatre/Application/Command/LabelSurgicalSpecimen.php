<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mark a specimen as labeled (COLLECTED → LABELED) (17-theatre-domain.md §74;
 * ADR-164 D-C). Optional — hand-off may go straight from COLLECTED. No domain
 * event (an internal chain-of-custody step).
 */
final readonly class LabelSurgicalSpecimen implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $specimenId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($specimenId)) {
            throw new InvalidArgumentException('LabelSurgicalSpecimen::$specimenId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('LabelSurgicalSpecimen::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): string
    {
        return $this->specimenId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.surgical_specimen_labeled',
            actorId: $this->actorId,
            targetType: 'surgical_specimen',
            targetId: $this->specimenId,
        );
    }
}
