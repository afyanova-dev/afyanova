<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The laboratory rejects a submitted order (ORDERED → REJECTED)
 * (14-laboratory-domain.md §24; ADR-157 D-F). `$reason` is free text — it is
 * stored row-only on `lab_orders.rejection_reason` and on the audit row
 * (ADR-136 allows `reason` on audit), never in the `LabOrderRejected` event.
 */
final readonly class RejectLabOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $orderId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('RejectLabOrder::$orderId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RejectLabOrder::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'reject';
    }

    public function subjectId(): string
    {
        return $this->orderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.order_rejected',
            actorId: $this->actorId,
            targetType: 'lab_order',
            targetId: $this->orderId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
