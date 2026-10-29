<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\ValueObject\PickupCode;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cancel a pickup — {READY_FOR_PICKUP | NOT_COLLECTED} → CANCELLED
 * (16-pharmacy-domain.md §29; ADR-186 D-D). For a duplicate / a cancelled order.
 * `ReverseDispensingHandler` cancels a pickup automatically
 * (`outcome_reason_code = 'DISPENSING_REVERSED'`) — that path does NOT go through
 * this command. Does NOT return stock. `note` is ROW-ONLY. Authorizes against
 * the order (`pickup`).
 */
final readonly class CancelPickup implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $actorId,
        public string $pharmacyOrderId,
        public string $dispensingId,
        string $reasonCode,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['actorId' => $actorId, 'pharmacyOrderId' => $pharmacyOrderId, 'dispensingId' => $dispensingId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CancelPickup::\${$name} must be a UUID (IC §3).");
            }
        }

        $this->reasonCode = PickupCode::assertOutcome($reasonCode);
    }

    public function policyAbility(): string
    {
        return 'pickup';
    }

    public function subjectId(): string
    {
        return $this->pharmacyOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.pickup_cancelled',
            actorId: $this->actorId,
            targetType: 'pickup',
            targetId: $this->dispensingId,
            reason: $this->note !== null && trim($this->note) !== '' ? trim($this->note) : null,
        );
    }
}
