<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Approve a submitted purchase requisition (19-procurement-domain.md §21, §22,
 * §24; ADR-171 D-F). The approver must not be the requester (§24 / Inv 29 — F2).
 */
final readonly class ApprovePurchaseRequisition implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $requisitionId,
        public string $actorId,
    ) {
        if (! Str::isUuid($requisitionId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ApprovePurchaseRequisition ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'approve';
    }

    public function subjectId(): string
    {
        return $this->requisitionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.requisition_approved',
            actorId: $this->actorId,
            targetType: 'purchase_requisition',
            targetId: $this->requisitionId,
        );
    }
}
