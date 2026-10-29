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
 * Mark a pickup NOT_COLLECTED — READY_FOR_PICKUP → NOT_COLLECTED
 * (16-pharmacy-domain.md §29; ADR-186 D-D).
 *
 * Does NOT return stock — a follow-up `ReverseDispensing` (ADR-185, a distinct
 * `pharmacy.dispensing.reverse` action) is required to put the medication back.
 * A NOT_COLLECTED pickup may still reach PICKED_UP for a late collection. `note`
 * is ROW-ONLY (ADR-136). Authorizes against the order (`pickup`).
 */
final readonly class MarkPickupNotCollected implements Auditable, AuthorizesAgainst
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
                throw new InvalidArgumentException("MarkPickupNotCollected::\${$name} must be a UUID (IC §3).");
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
            action: 'pharmacy.pickup_not_collected',
            actorId: $this->actorId,
            targetType: 'pickup',
            targetId: $this->dispensingId,
            reason: $this->note !== null && trim($this->note) !== '' ? trim($this->note) : null,
        );
    }
}
