<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The laboratory accepts a submitted order for processing (ORDERED → ACCEPTED)
 * (14-laboratory-domain.md §24; ADR-157 D-F). A distinct actor from the
 * ordering clinician (§22) — the `laboratory.orders.accept` key.
 */
final readonly class AcceptLabOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $orderId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('AcceptLabOrder::$orderId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AcceptLabOrder::$actorId must be a UUID (IC §3).');
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
            action: 'laboratory.order_accepted',
            actorId: $this->actorId,
            targetType: 'lab_order',
            targetId: $this->orderId,
        );
    }
}
