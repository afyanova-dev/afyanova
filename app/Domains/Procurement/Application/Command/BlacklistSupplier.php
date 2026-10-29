<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Blacklist a supplier — block it from new procurement (19-procurement-domain.md
 * §15; ADR-171 D-D — F5d, a distinct command from `ChangeSupplierStatus`
 * because §15 blacklisting is materially weightier). BLACKLISTED is terminal in
 * Proc.1. Existing ISSUED POs are NOT auto-cancelled (F5b). `reason` mandatory
 * + ROW-ONLY.
 */
final readonly class BlacklistSupplier implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $supplierId,
        public string $actorId,
        public string $reason,
    ) {
        if (! Str::isUuid($supplierId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('BlacklistSupplier ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('BlacklistSupplier::$reason must not be blank (§15).');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->supplierId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.supplier_blacklisted',
            actorId: $this->actorId,
            targetType: 'supplier',
            targetId: $this->supplierId,
            reason: trim($this->reason),
        );
    }
}
