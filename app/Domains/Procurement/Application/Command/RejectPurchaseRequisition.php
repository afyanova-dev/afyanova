<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reject a submitted purchase requisition (19-procurement-domain.md §21;
 * ADR-171 D-F). `reason` is mandatory + ROW-ONLY.
 */
final readonly class RejectPurchaseRequisition implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $requisitionId,
        public string $actorId,
        public string $reason,
    ) {
        if (! Str::isUuid($requisitionId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RejectPurchaseRequisition ids must be UUIDs (IC §3).');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('RejectPurchaseRequisition::$reason must not be blank.');
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
            action: 'procurement.requisition_rejected',
            actorId: $this->actorId,
            targetType: 'purchase_requisition',
            targetId: $this->requisitionId,
            reason: trim($this->reason),
        );
    }
}
