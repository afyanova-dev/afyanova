<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\ValueObject\DispensingReversalReason;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reverse a COMPLETED Dispensing — the error-correction path
 * (16-pharmacy-domain.md §59, §60, §128; ADR-185 D-D).
 *
 * FULL reversal only: the whole dispensing event is undone — the exact stock is
 * returned (`InventoryApi::reverseConsumption`), a `DispensingReversal` record is
 * written (the original `rx_dispensings` row is preserved — §128), and the
 * PharmacyOrder rollup is re-computed (§112 headroom is freed). ONE reversal per
 * Dispensing.
 *
 * Carries BOTH `pharmacyOrderId` and `dispensingId`: the bus authorizes against
 * the ORDER (`reverse` — `pharmacy.dispensing.reverse` at the order's facility;
 * §110 "high-risk overrides must be audited"), the handler cross-checks the
 * dispensing belongs to it. `reasonNote` is ROW-ONLY (ADR-136).
 */
final readonly class ReverseDispensing implements Auditable, AuthorizesAgainst
{
    public string $reasonCode;

    public function __construct(
        public string $reversalId,
        public string $actorId,
        public string $pharmacyOrderId,
        public string $dispensingId,
        string $reasonCode,
        public ?string $reasonNote = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'reversalId' => $reversalId,
            'actorId' => $actorId,
            'pharmacyOrderId' => $pharmacyOrderId,
            'dispensingId' => $dispensingId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("ReverseDispensing::\${$name} must be a UUID (IC §3).");
            }
        }

        $this->reasonCode = DispensingReversalReason::assert($reasonCode);
    }

    public function policyAbility(): string
    {
        return 'reverse';
    }

    public function subjectId(): string
    {
        return $this->pharmacyOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.dispensing_reversed',
            actorId: $this->actorId,
            targetType: 'dispensing_reversal',
            targetId: $this->reversalId,
            metadata: [
                'dispensing_id' => $this->dispensingId,
                'pharmacy_order_id' => $this->pharmacyOrderId,
                'reason_code' => $this->reasonCode,
            ],
            reason: $this->reasonNote !== null && trim($this->reasonNote) !== '' ? trim($this->reasonNote) : null,
        );
    }
}
