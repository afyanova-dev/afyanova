<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\Service\PharmacyOrderLifecycle;

/**
 * Orchestrates `RejectPharmacyOrder` (ADR-182 D-D). `getByIdForUpdate` →
 * `assertRejectable` (RECEIVED | VALIDATED) → `->reject()` (records
 * `PharmacyOrderRejected`; coded reason only; `reason_note` ROW-ONLY) → save.
 */
final class RejectPharmacyOrderHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly PharmacyOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(RejectPharmacyOrder $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->pharmacyOrderId);

        $this->lifecycle->assertRejectable((string) $order->status);

        $order->reject($command->actorId, $command->reasonCode, $command->note);

        $this->orders->save($order);
    }
}
