<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Submit a DRAFT purchase order for approval (19-procurement-domain.md §44; ADR-171 D-H). */
final readonly class SubmitPurchaseOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $purchaseOrderId,
        public string $actorId,
    ) {
        if (! Str::isUuid($purchaseOrderId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SubmitPurchaseOrder ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'submit';
    }

    public function subjectId(): string
    {
        return $this->purchaseOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.order_submitted',
            actorId: $this->actorId,
            targetType: 'purchase_order',
            targetId: $this->purchaseOrderId,
        );
    }
}
