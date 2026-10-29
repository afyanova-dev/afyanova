<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Dispense medication against a VALIDATED (or PARTIALLY_DISPENSED) Pharmacy Order
 * (16-pharmacy-domain.md §24, §44–§47; ADR-184 D-H).
 *
 * One dispensing EVENT — the pharmacist selects the stockable item
 * (`$itemId` — an `inv_items.id`; B1 manual selection) and the location, and the
 * quantity to release now. The handler reserves + consumes exactly `$quantity`
 * of stock and writes the `Dispensing` record; §112 / §113 are enforced against
 * the C.10 authorised quantity (ADR-183) when the prescription carries one.
 *
 * Authorizes against the Pharmacy Order (`dispense` — `pharmacy.dispensing.record`
 * at the order's facility). The acting pharmacist must ALSO hold
 * `inventory.reservations.manage` + `inventory.consumption.record` +
 * `inventory.access` (the nested Inventory calls) and `clinical.prescribing.read`
 * (the first-event `ClinicalApi::getPrescribingDecision`) — ADR-184 HD-5.
 */
final readonly class Dispense implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $dispensingId,
        public string $actorId,
        public string $pharmacyOrderId,
        public string $itemId,
        public string $locationId,
        public string $quantity,
        public ?string $batchNumber = null,
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach ([
            'dispensingId' => $dispensingId,
            'actorId' => $actorId,
            'pharmacyOrderId' => $pharmacyOrderId,
            'itemId' => $itemId,
            'locationId' => $locationId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("Dispense::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! is_numeric($quantity) || (float) $quantity <= 0) {
            throw new InvalidArgumentException('Dispense::$quantity must be a positive numeric string.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('Dispense::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('Dispense::$occurredAt must not be in the future.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'dispense';
    }

    public function subjectId(): string
    {
        return $this->pharmacyOrderId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'pharmacy.medication_dispensed',
            actorId: $this->actorId,
            targetType: 'dispensing',
            targetId: $this->dispensingId,
            metadata: [
                'pharmacy_order_id' => $this->pharmacyOrderId,
                'item_id' => $this->itemId,
                'location_id' => $this->locationId,
                'quantity' => $this->quantity,
            ],
        );
    }
}
