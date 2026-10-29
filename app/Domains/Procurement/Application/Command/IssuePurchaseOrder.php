<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Issue an approved purchase order to the supplier (19-procurement-domain.md
 * §44, §124, §228, §238; ADR-171 D-H). The issuer must not be the user who
 * approved it (§228 "Different Approver / Different Buyer" / Inv 29 — F2).
 * Emits `PurchaseOrderIssued` — THE key Procurement signal.
 */
final readonly class IssuePurchaseOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $purchaseOrderId,
        public string $actorId,
    ) {
        if (! Str::isUuid($purchaseOrderId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('IssuePurchaseOrder ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'issue';
    }

    public function subjectId(): string
    {
        return $this->purchaseOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.order_issued',
            actorId: $this->actorId,
            targetType: 'purchase_order',
            targetId: $this->purchaseOrderId,
        );
    }
}
