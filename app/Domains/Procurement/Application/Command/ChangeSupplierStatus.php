<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Model\Supplier;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Move a supplier between SUSPENDED / ACTIVE / INACTIVE (19-procurement-domain.md
 * §12, §14; ADR-171 D-D). BLACKLISTED is a distinct command (`BlacklistSupplier`
 * — F5d). `reason` is mandatory + ROW-ONLY.
 */
final readonly class ChangeSupplierStatus implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $supplierId,
        public string $actorId,
        public string $targetStatus,
        public string $reason,
    ) {
        if (! Str::isUuid($supplierId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ChangeSupplierStatus ids must be UUIDs (IC §3).');
        }

        if (! in_array(strtoupper(trim($targetStatus)), [
            Supplier::STATUS_SUSPENDED, Supplier::STATUS_ACTIVE, Supplier::STATUS_INACTIVE,
        ], true)) {
            throw new InvalidArgumentException('ChangeSupplierStatus::$targetStatus must be SUSPENDED|ACTIVE|INACTIVE (BLACKLIST is a distinct command — ADR-171 D-D).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('ChangeSupplierStatus::$reason must not be blank (§12).');
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
            action: 'procurement.supplier_status_changed',
            actorId: $this->actorId,
            targetType: 'supplier',
            targetId: $this->supplierId,
            reason: trim($this->reason),
            metadata: ['target_status' => strtoupper(trim($this->targetStatus))],
        );
    }
}
