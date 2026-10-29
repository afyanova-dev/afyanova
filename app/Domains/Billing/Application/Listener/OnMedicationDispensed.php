<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Listener;

use App\Domains\Billing\Application\Command\CaptureBillableEvent;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensed;
use App\Shared\Bus\CommandBus;

/**
 * Reacts to Pharmacy's dispensing fact and captures the billable event
 * (20-billing-and-payments-domain.md §12 / §225; ADR-136; ADR-189 D-E).
 *
 * The 3rd event listener in the engagement (IC §7.3 shape — after
 * `OnStockReceived` / `OnPrescriptionIssued`). The `OutboxRelay` hydrates the
 * event and dispatches a queued `DispatchListenerJob` that re-establishes the
 * tenant context and invokes this plain invokable. It does exactly one thing:
 * map the event to the `CaptureBillableEvent` system command, keyed for
 * idempotency on the event id. All the charge logic lives in the handler.
 */
final class OnMedicationDispensed
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(MedicationDispensed $event): void
    {
        $this->bus->dispatch(new CaptureBillableEvent(
            sourceEventId: $event->eventId,
            dispensingId: $event->dispensingId,
            patientId: $event->patientId,
            facilityId: $event->facilityId,
            itemId: $event->itemId,
            quantity: $event->quantity,
            dispensedByUserId: $event->dispensedByUserId,
            occurredAt: $event->occurredAt,
            idempotencyKey: $event->eventId,
        ));
    }
}
