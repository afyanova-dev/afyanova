<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Radiology accepts a submitted imaging order for processing (ORDERED →
 * ACCEPTED) (15-radiology-domain.md §20; ADR-165 D-G). A distinct actor from
 * the ordering clinician (§22) — the `radiology.orders.accept` key.
 */
final readonly class AcceptImagingOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $orderId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('AcceptImagingOrder::$orderId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcceptImagingOrder::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'accept';
    }

    public function subjectId(): string
    {
        return $this->orderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.order_accepted',
            actorId: $this->actorId,
            targetType: 'imaging_order',
            targetId: $this->orderId,
        );
    }
}
