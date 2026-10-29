<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record that the laboratory confirmed receipt of a handed-off specimen
 * (HANDED_OVER → RECEIVED) (17-theatre-domain.md §74, §177; ADR-164 D-C).
 * `$labAccessionRef` is the OPAQUE accession the lab assigned (§177 — Theatre
 * retains its own identity; no cross-domain call). Nominally a lab-liaison /
 * courier action. No domain event.
 */
final readonly class MarkSurgicalSpecimenReceived implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $specimenId,
        public string $actorId,
        public ?string $labAccessionRef = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($specimenId)) {
            throw new InvalidArgumentException('MarkSurgicalSpecimenReceived::$specimenId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkSurgicalSpecimenReceived::$actorId must be a UUID (IC §3).');
        }

        if ($labAccessionRef !== null && trim($labAccessionRef) === '') {
            throw new InvalidArgumentException('MarkSurgicalSpecimenReceived::$labAccessionRef must be a non-blank string or null.');
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
            action: 'theatre.surgical_specimen_received',
            actorId: $this->actorId,
            targetType: 'surgical_specimen',
            targetId: $this->specimenId,
            metadata: $this->labAccessionRef !== null ? ['lab_accession_ref' => trim($this->labAccessionRef)] : [],
        );
    }
}
