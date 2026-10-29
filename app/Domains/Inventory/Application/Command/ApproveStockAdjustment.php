<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Approve a pending stock adjustment — posts the compensating movement and
 * updates the balance in the same transaction
 * (18-inventory-and-warehousing-domain.md §94, §95, §224, §250; ADR-170 D-H).
 * The approver must not be the requester (§224 / Inv 12).
 */
final readonly class ApproveStockAdjustment implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $adjustmentId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($adjustmentId)) {
            throw new InvalidArgumentException('ApproveStockAdjustment::$adjustmentId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ApproveStockAdjustment::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'approve';
    }

    public function subjectId(): string
    {
        return $this->adjustmentId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'inventory.stock_adjustment_approved',
            actorId: $this->actorId,
            targetType: 'stock_adjustment',
            targetId: $this->adjustmentId,
        );
    }
}
