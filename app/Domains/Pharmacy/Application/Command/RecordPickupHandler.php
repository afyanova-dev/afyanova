<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Billing\Contracts\BillingApi;
use App\Domains\Pharmacy\Domain\Exception\DispensingPaymentNotCleared;
use App\Domains\Pharmacy\Domain\Exception\PickupMismatch;
use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\PickupRepository;
use App\Domains\Pharmacy\Domain\Service\PickupLifecycle;
use Carbon\CarbonImmutable;

/**
 * Orchestrates `RecordPickup` (ADR-186 D-D).
 *
 * `PharmacyOrderRepository` is FIRST so the bus resolves
 * `PharmacyOrderPolicy::pickup` (per-subject `pharmacy.pickup.record` at the
 * order's facility). The handler loads the Pickup by dispensing_id via
 * `forDispensingForUpdate` — the `rx_pickups` row lock that serialises with
 * `ReverseDispensingHandler` (ADR-186 D-E) — cross-checks the order, then
 * transitions.
 */
final class RecordPickupHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly PickupRepository $pickups,
        private readonly PickupLifecycle $lifecycle,
        private readonly BillingApi $billing,
    ) {}

    public function __invoke(RecordPickup $command): void
    {
        $this->orders->getById($command->pharmacyOrderId); // RLS / existence

        $pickup = $this->pickups->forDispensingForUpdate($command->dispensingId);

        if ((string) $pickup->pharmacy_order_id !== $command->pharmacyOrderId) {
            throw PickupMismatch::orderPickup(
                $command->pharmacyOrderId,
                $command->dispensingId,
                (string) $pickup->pharmacy_order_id,
            );
        }

        $this->lifecycle->assertCanRecord((string) $pickup->status);

        if (! $this->billing->dispensingClearedForHandover($command->dispensingId)) {
            throw new DispensingPaymentNotCleared($command->dispensingId);
        }

        $now = CarbonImmutable::now();

        $pickup->recordCollection(
            collectedByUserId: $command->actorId,
            recipientType: $command->recipientType,
            recipientName: $command->recipientName,
            verificationNote: $command->verificationNote,
            collectedAt: $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now,
        );

        $this->pickups->save($pickup);
    }
}
