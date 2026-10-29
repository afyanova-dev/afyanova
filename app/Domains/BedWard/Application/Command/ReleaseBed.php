<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Domains\BedWard\Domain\ValueObject\BedEndReason;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * End an occupancy and release the bed to CLEANING status (24-bed-and-ward-management-domain.md §9; ADR-148 D-G).
 */
final readonly class ReleaseBed implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $occupancyId,
        public string $actorId,
        public string $endReason,
        public ?CarbonImmutable $endedAt = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($occupancyId)) {
            throw new InvalidArgumentException('ReleaseBed::$occupancyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReleaseBed::$actorId must be a UUID (IC §3).');
        }

        BedEndReason::assert($endReason);
    }

    public function policyAbility(): string
    {
        return 'release';
    }

    public function subjectId(): string
    {
        return $this->occupancyId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.bed_released',
            actorId: $this->actorId,
            targetType: 'bed_occupancy',
            targetId: $this->occupancyId,
            metadata: [
                'end_reason' => $this->endReason,
            ],
        );
    }
}
