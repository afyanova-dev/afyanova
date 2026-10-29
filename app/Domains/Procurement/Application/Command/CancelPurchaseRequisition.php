<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** 19-procurement-domain.md §21; ADR-171 D-F. */
final readonly class CancelPurchaseRequisition implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $requisitionId,
        public string $actorId,
    ) {
        if (! Str::isUuid($requisitionId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelPurchaseRequisition ids must be UUIDs (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->requisitionId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procurement.requisition_cancelled',
            actorId: $this->actorId,
            targetType: 'purchase_requisition',
            targetId: $this->requisitionId,
        );
    }
}
