<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\BillingServiceRepository;

/**
 * Orchestrates `UpdateBillingService` (ADR-188 D-C). `getByIdForUpdate` →
 * `rename()` (name / category only) → save.
 */
final class UpdateBillingServiceHandler
{
    public function __construct(private readonly BillingServiceRepository $services) {}

    public function __invoke(UpdateBillingService $command): void
    {
        $service = $this->services->getByIdForUpdate($command->billingServiceId);

        $service->rename($command->name, $command->category);

        $this->services->save($service);
    }
}
