<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\Service\PharmacyOrderLifecycle;

/**
 * Orchestrates `HoldPharmacyOrder` (ADR-182 D-D). `getByIdForUpdate` →
 * `assertHoldable` (RECEIVED | VALIDATED) → `->hold()` (no event — internal
 * churn) → save.
 */
final class HoldPharmacyOrderHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly PharmacyOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(HoldPharmacyOrder $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->pharmacyOrderId);

        $this->lifecycle->assertHoldable((string) $order->status);

        $order->hold($command->reasonCode, $command->note);

        $this->orders->save($order);
    }
}
