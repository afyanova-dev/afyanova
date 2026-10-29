<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Contracts\Event;

/**
 * Medication was dispensed against a Pharmacy Order — a `Dispensing` record was
 * created and the stock consumed (16-pharmacy-domain.md §24, §99; ADR-184 D-I).
 *
 * Written to `shared_outbox_events` in the `Dispense` transaction and relayed
 * asynchronously; idempotent consumers key on `eventId`. NOT broadcast. NO
 * Pharm.3 consumer — the named future consumers are Billing charge-capture
 * (§99–§105, PHASE 10) and the Medication Administration Record (§25 / §86).
 *
 * PHI-MINIMIZED (the ADR-136 deny-list, IC §7.1.1): identifiers + the coded
 * `itemId` + the `quantity` + the (nullable) `batchNumber`. NO medication name /
 * code, NO patient demographics, NO dosing text.
 */
final readonly class MedicationDispensed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $dispensingId,
        public string $pharmacyOrderId,
        public string $prescriptionId,
        public string $itemId,
        public string $quantity,
        public ?string $batchNumber,
        public string $dispensedByUserId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
