<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\Exception\PickupMismatch;
use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\PickupRepository;
use App\Domains\Pharmacy\Domain\Service\PickupLifecycle;

/**
 * Orchestrates `CancelPickup` (ADR-186 D-D). `PharmacyOrderRepository` first →
 * `PharmacyOrderPolicy::pickup`.
 */
final class CancelPickupHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly PickupRepository $pickups,
        private readonly PickupLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelPickup $command): void
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

        $this->lifecycle->assertCanCancel((string) $pickup->status);

        $pickup->cancel($command->reasonCode, $command->note);

        $this->pickups->save($pickup);
    }
}
