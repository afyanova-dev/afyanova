<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Block a bed for maintenance or isolation (24-bed-and-ward-management-domain.md §9; ADR-148 D-G).
 */
final readonly class BlockBed implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $bedId,
        public string $actorId,
        public string $reason,
        public bool $isMaintenance = false,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($bedId)) {
            throw new InvalidArgumentException('BlockBed::$bedId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('BlockBed::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('BlockBed::$reason must not be empty.');
        }
    }

    public function policyAbility(): string
    {
        return 'block';
    }

    public function subjectId(): string
    {
        return $this->bedId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.bed_blocked',
            actorId: $this->actorId,
            targetType: 'bed',
            targetId: $this->bedId,
            reason: $this->reason,
            metadata: [
                'is_maintenance' => $this->isMaintenance,
            ],
        );
    }
}
