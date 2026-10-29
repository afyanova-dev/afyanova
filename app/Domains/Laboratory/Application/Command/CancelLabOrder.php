<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The ordering side withdraws a laboratory order before the laboratory has
 * accepted it (DRAFT|ORDERED → CANCELLED) (14-laboratory-domain.md §24;
 * ADR-157 D-F). `$reason` is free text — stored row-only on
 * `lab_orders.cancellation_reason` and on the audit row, never in the event.
 */
final readonly class CancelLabOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $orderId,
        public string $actorId,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('CancelLabOrder::$orderId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelLabOrder::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'cancel';
    }

    public function subjectId(): string
    {
        return $this->orderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.order_cancelled',
            actorId: $this->actorId,
            targetType: 'lab_order',
            targetId: $this->orderId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
