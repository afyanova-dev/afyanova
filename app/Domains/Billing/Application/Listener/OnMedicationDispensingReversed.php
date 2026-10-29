<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Listener;

use App\Domains\Billing\Application\Command\ReverseBillableEvent;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensingReversed;
use App\Shared\Bus\CommandBus;

/**
 * Reacts to Pharmacy's dispensing-reversal fact and reverses the facility Charge
 * (20-billing-and-payments-domain.md §113 / §135; ADR-136; ADR-189 D-F).
 *
 * Maps the event to the `ReverseBillableEvent` system command, keyed for
 * idempotency on the reversal event id. All the reversal logic lives in the
 * handler.
 */
final class OnMedicationDispensingReversed
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(MedicationDispensingReversed $event): void
    {
        $this->bus->dispatch(new ReverseBillableEvent(
            reversalEventId: $event->eventId,
            dispensingId: $event->dispensingId,
            reasonCode: $event->reasonCode,
            occurredAt: $event->occurredAt,
            idempotencyKey: $event->eventId,
        ));
    }
}
