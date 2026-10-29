<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Radiology rejects a submitted imaging order (ORDERED → REJECTED)
 * (15-radiology-domain.md §20; ADR-165 D-G). `$reason` is free text — stored
 * row-only on `rad_imaging_orders.rejection_reason` and on the audit row
 * (ADR-136 allows `reason` on audit), never in the `ImagingOrderRejected`
 * event.
 */
final readonly class RejectImagingOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $orderId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('RejectImagingOrder::$orderId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RejectImagingOrder::$actorId must be a UUID (IC §3).');
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
            action: 'radiology.order_rejected',
            actorId: $this->actorId,
            targetType: 'imaging_order',
            targetId: $this->orderId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
