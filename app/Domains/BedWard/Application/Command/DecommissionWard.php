<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class DecommissionWard implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $wardId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($wardId)) {
            throw new InvalidArgumentException('DecommissionWard::$wardId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('DecommissionWard::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'decommission';
    }

    public function subjectId(): string
    {
        return $this->wardId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'bed_ward.ward_decommissioned',
            actorId: $this->actorId,
            targetType: 'ward',
            targetId: $this->wardId,
        );
    }
}
