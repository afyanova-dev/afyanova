<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Command;

use App\Domains\Pharmacy\Domain\PharmacyOrderRepository;
use App\Domains\Pharmacy\Domain\Service\PharmacyOrderLifecycle;

/**
 * Orchestrates `ValidatePharmacyOrder` (ADR-182 D-D). `getByIdForUpdate` →
 * `assertValidatable` (RECEIVED) → `->validate()` (records
 * `PharmacyOrderValidated`) → save.
 *
 * `PharmacyOrderRepository` first → the bus resolves `PharmacyOrderPolicy::manage`
 * (per-subject, at the order's facility).
 */
final class ValidatePharmacyOrderHandler
{
    public function __construct(
        private readonly PharmacyOrderRepository $orders,
        private readonly PharmacyOrderLifecycle $lifecycle,
    ) {}

    public function __invoke(ValidatePharmacyOrder $command): void
    {
        $order = $this->orders->getByIdForUpdate($command->pharmacyOrderId);

        $this->lifecycle->assertValidatable((string) $order->status);

        $order->validate($command->actorId);

        $this->orders->save($order);
    }
}
