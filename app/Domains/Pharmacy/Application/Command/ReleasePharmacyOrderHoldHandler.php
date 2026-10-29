<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\Service\PharmacyOrderLifecycle;

/**
 * Orchestrates `ReleasePharmacyOrderHold` (ADR-182 D-D). `getByIdForUpdate` →
 * `assertReleasable` (ON_HOLD) → `->releaseHold()` (→ RECEIVED; no event) → save.
 */
final class ReleasePharmacyOrderHoldHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly PharmacyOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(ReleasePharmacyOrderHold $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->pharmacyOrderId);

        $this->lifecycle->assertReleasable((string) $order->status);

        $order->releaseHold();

        $this->orders->save($order);
    }
}
