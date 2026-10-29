<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Assign an inpatient encounter to an available bed (24-bed-and-ward-management-domain.md §9;
 * ADR-147 D-G, ADR-148 D-F/D-G).
 */
final readonly class AssignBed implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $occupancyId,
        public string $encounterId,
        public string $bedId,
        public string $actorId,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($occupancyId)) {
            throw new InvalidArgumentException('AssignBed::$occupancyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('AssignBed::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($bedId)) {
            throw new InvalidArgumentException('AssignBed::$bedId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AssignBed::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'assign';
    }

    public function subjectId(): string
    {
        return $this->bedId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.bed_assigned',
            actorId: $this->actorId,
            targetType: 'bed_occupancy',
            targetId: $this->occupancyId,
            metadata: [
                'encounter_id' => $this->encounterId,
                'bed_id' => $this->bedId,
            ],
        );
    }
}
