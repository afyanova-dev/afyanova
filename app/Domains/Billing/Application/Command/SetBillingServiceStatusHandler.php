<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\BillingServiceRepository;
use App\Domains\Billing\Domain\Model\BillingService;

/**
 * Orchestrates `SetBillingServiceStatus` (ADR-188 D-C). `getByIdForUpdate` →
 * `activate()` / `deactivate()` → save. Tariff rows are untouched.
 */
final class SetBillingServiceStatusHandler
{
    public function __construct(private readonly BillingServiceRepository $services) {}

    public function __invoke(SetBillingServiceStatus $command): void
    {
        $service = $this->services->getByIdForUpdate($command->billingServiceId);

        if ($command->status === BillingService::STATUS_ACTIVE) {
            $service->activate();
        } else {
            $service->deactivate();
        }

        $this->services->save($service);
    }
}
