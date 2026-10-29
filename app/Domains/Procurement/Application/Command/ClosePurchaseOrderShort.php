<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Close the outstanding balance of a purchase order short (Phase 1 / P1-10).
 *
 * Legal only from ISSUED / PARTIALLY_RECEIVED. The reason is mandatory and
 * ROW-ONLY. Goods already received stay in stock; no further receipt can post
 * against the order.
 */
final readonly class ClosePurchaseOrderShort implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $purchaseOrderId,
        public string $actorId,
        public string $reason,
    ) {
        if (! Str::isUuid($purchaseOrderId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ClosePurchaseOrderShort ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('ClosePurchaseOrderShort::$reason must not be blank (P1-10).');
        }
    }

    public function policyAbility(): string
    {
        return 'closeShort';
    }

    public function subjectId(): string
    {
        return $this->purchaseOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.order_closed_short',
            actorId: $this->actorId,
            targetType: 'purchase_order',
            targetId: $this->purchaseOrderId,
            reason: trim($this->reason),
        );
    }
}
