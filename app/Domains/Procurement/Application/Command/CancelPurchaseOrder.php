<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cancel a purchase order (19-procurement-domain.md §73, §74; ADR-171 D-H).
 * Legal from DRAFT | SUBMITTED | APPROVED | ISSUED. `reason` is mandatory +
 * ROW-ONLY (NEVER the event — ADR-136).
 */
final readonly class CancelPurchaseOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $purchaseOrderId,
        public string $actorId,
        public string $reason,
    ) {
        if (! Str::isUuid($purchaseOrderId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelPurchaseOrder ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('CancelPurchaseOrder::$reason must not be blank (§73).');
        }
    }

    public function policyAbility(): string
    {
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->purchaseOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.order_cancelled',
            actorId: $this->actorId,
            targetType: 'purchase_order',
            targetId: $this->purchaseOrderId,
            reason: trim($this->reason),
        );
    }
}
