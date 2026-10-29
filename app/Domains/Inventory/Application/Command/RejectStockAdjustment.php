<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reject a pending stock adjustment — no stock change
 * (18-inventory-and-warehousing-domain.md §94, §95; ADR-170 D-H). `reason` is
 * free text, row-only + on the audit row (the ADR-136 deny-list).
 */
final readonly class RejectStockAdjustment implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $adjustmentId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($adjustmentId)) {
            throw new InvalidArgumentException('RejectStockAdjustment::$adjustmentId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RejectStockAdjustment::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'reject';
    }

    public function subjectId(): string
    {
        return $this->adjustmentId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_adjustment_rejected',
            actorId: $this->actorId,
            targetType: 'stock_adjustment',
            targetId: $this->adjustmentId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
