<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Submit a DRAFT laboratory order for the laboratory to act on (DRAFT →
 * ORDERED) (14-laboratory-domain.md §24; ADR-157 D-F).
 */
final readonly class SubmitLabOrder implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $orderId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('SubmitLabOrder::$orderId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('SubmitLabOrder::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'submit';
    }

    public function subjectId(): string
    {
        return $this->orderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.order_submitted',
            actorId: $this->actorId,
            targetType: 'lab_order',
            targetId: $this->orderId,
        );
    }
}
