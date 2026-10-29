<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Approve a submitted purchase order (19-procurement-domain.md §44, §45, §228;
 * ADR-171 D-H). The approver must not be the PO's creator (§228 / Inv 29 — F2).
 */
final readonly class ApprovePurchaseOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $purchaseOrderId,
        public string $actorId,
    ) {
        if (! Str::isUuid($purchaseOrderId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ApprovePurchaseOrder ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'approve';
    }

    public function subjectId(): string
    {
        return $this->purchaseOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.order_approved',
            actorId: $this->actorId,
            targetType: 'purchase_order',
            targetId: $this->purchaseOrderId,
        );
    }
}
