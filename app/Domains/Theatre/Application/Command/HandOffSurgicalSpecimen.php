<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Hand a specimen over to the laboratory ({COLLECTED|LABELED} → HANDED_OVER)
 * (17-theatre-domain.md §73–§74, §289; ADR-164 D-C). `$destinationLaboratory`
 * is required here (open-coded, normalised uppercase in the handler). A
 * duplicate hand-off is rejected (§318).
 */
final readonly class HandOffSurgicalSpecimen implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $specimenId,
        public string $actorId,
        public string $destinationLaboratory,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($specimenId)) {
            throw new InvalidArgumentException('HandOffSurgicalSpecimen::$specimenId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('HandOffSurgicalSpecimen::$actorId must be a UUID (IC §3).');
        }

        if (trim($destinationLaboratory) === '') {
            throw new InvalidArgumentException('HandOffSurgicalSpecimen::$destinationLaboratory must not be blank (§73).');
        }
    }

    public function policyAbility(): string
    {
        return 'handoff';
    }

    public function subjectId(): string
    {
        return $this->specimenId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.surgical_specimen_handed_off',
            actorId: $this->actorId,
            targetType: 'surgical_specimen',
            targetId: $this->specimenId,
            metadata: ['destination_laboratory' => strtoupper(trim($this->destinationLaboratory))],
        );
    }
}
