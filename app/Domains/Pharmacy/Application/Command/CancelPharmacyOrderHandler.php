<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\Service\PharmacyOrderLifecycle;

/**
 * Orchestrates `CancelPharmacyOrder` (ADR-182 D-D). `getByIdForUpdate` →
 * `assertCancellable` (any non-terminal) → `->cancel()` (records
 * `PharmacyOrderCancelled`; coded reason only) → save.
 */
final class CancelPharmacyOrderHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly PharmacyOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(CancelPharmacyOrder $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->pharmacyOrderId);

        $this->lifecycle->assertCancellable((string) $order->status);

        $order->cancel($command->actorId, $command->reasonCode, $command->note);

        $this->orders->save($order);
    }
}
