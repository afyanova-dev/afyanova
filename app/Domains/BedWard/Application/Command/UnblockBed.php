<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Unblock a blocked or maintenance bed (24-bed-and-ward-management-domain.md §9; ADR-148 D-G).
 */
final readonly class UnblockBed implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $bedId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($bedId)) {
            throw new InvalidArgumentException('UnblockBed::$bedId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UnblockBed::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'unblock';
    }

    public function subjectId(): string
    {
        return $this->bedId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.bed_unblocked',
            actorId: $this->actorId,
            targetType: 'bed',
            targetId: $this->bedId,
        );
    }
}
