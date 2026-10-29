<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Capture a `pharmacy.MedicationDispensed` fact and (if resolvable) create the
 * facility Charge (20-billing-and-payments-domain.md §10 / §14 / §225;
 * ADR-189 D-E).
 *
 * SYSTEM command — dispatched only by the `OnMedicationDispensed` listener. No
 * `AuthorizesAgainst` / `Auditable` / permission key (IC §7.3; the
 * `IngestPrescription` / `RecordPurchaseOrderReceipt` shape). Idempotent:
 * `idempotencyKey` defaults to `sourceEventId`; the handler no-ops on a
 * terminal `bill_billable_events` row.
 */
final readonly class CaptureBillableEvent
{
    public string $idempotencyKey;

    public function __construct(
        public string $sourceEventId,
        public string $dispensingId,
        public string $patientId,
        public string $facilityId,
        public string $itemId,
        public string $quantity,
        public string $dispensedByUserId,
        public string $occurredAt,
        ?string $idempotencyKey = null,
    ) {
        foreach ([
            'sourceEventId' => $sourceEventId, 'dispensingId' => $dispensingId,
            'patientId' => $patientId, 'facilityId' => $facilityId,
            'itemId' => $itemId, 'dispensedByUserId' => $dispensedByUserId,
        ] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CaptureBillableEvent::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! is_numeric($quantity)) {
            throw new InvalidArgumentException('CaptureBillableEvent::$quantity must be numeric (IC §3).');
        }

        try {
            CarbonImmutable::parse($occurredAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('CaptureBillableEvent::$occurredAt must be an ISO-8601 datetime.');
        }

        $this->idempotencyKey = $idempotencyKey ?? $sourceEventId;
    }
}
