<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DecommissionBed implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $bedId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($bedId)) {
            throw new InvalidArgumentException('DecommissionBed::$bedId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('DecommissionBed::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'decommission';
    }

    public function subjectId(): string
    {
        return $this->bedId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.bed_decommissioned',
            actorId: $this->actorId,
            targetType: 'bed',
            targetId: $this->bedId,
        );
    }
}
