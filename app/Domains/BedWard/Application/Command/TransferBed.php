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
 * Transfer an inpatient from one bed to another (24-bed-and-ward-management-domain.md §9;
 * ADR-147 D-K, ADR-148 D-F/D-G).
 */
final readonly class TransferBed implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $encounterId,
        public string $fromBedId,
        public string $toBedId,
        public string $newOccupancyId,
        public string $actorId,
        public ?CarbonImmutable $transferredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('TransferBed::$encounterId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($fromBedId)) {
            throw new InvalidArgumentException('TransferBed::$fromBedId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($toBedId)) {
            throw new InvalidArgumentException('TransferBed::$toBedId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($newOccupancyId)) {
            throw new InvalidArgumentException('TransferBed::$newOccupancyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('TransferBed::$actorId must be a UUID (IC §3).');
        }

        if ($fromBedId === $toBedId) {
            throw new InvalidArgumentException('TransferBed: fromBedId and toBedId must not be the same bed.');
        }
    }

    public function policyAbility(): string
    {
        return 'transfer';
    }

    public function subjectId(): string
    {
        return $this->toBedId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.bed_transferred',
            actorId: $this->actorId,
            targetType: 'bed_occupancy',
            targetId: $this->newOccupancyId,
            metadata: [
                'encounter_id' => $this->encounterId,
                'from_bed_id' => $this->fromBedId,
                'to_bed_id' => $this->toBedId,
            ],
        );
    }
}
