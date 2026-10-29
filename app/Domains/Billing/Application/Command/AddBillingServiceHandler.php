<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\BillingServiceRepository;
use App\Domains\Billing\Domain\Exception\BillingServiceCodeTaken;
use App\Domains\Billing\Domain\Model\BillingService;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `AddBillingService` (ADR-188 D-C). `existsByCode` pre-check +
 * the `unique (tenant_id, code)` index as the race backstop →
 * `BillingServiceCodeTaken`.
 */
final class AddBillingServiceHandler
{
    public function __construct(
        private readonly BillingServiceRepository $services,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AddBillingService $command): void
    {
        if ($this->services->existsByCode($command->code)) {
            throw BillingServiceCodeTaken::code($command->code);
        }

        $now = CarbonImmutable::now();

        $service = BillingService::add(
            id: $command->billingServiceId,
            tenantId: $this->context->tenantId(),
            code: $command->code,
            name: $command->name,
            category: $command->category,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->services->save($service);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'bill_billing_services_tenant_id_code_unique')) {
                throw BillingServiceCodeTaken::code($command->code);
            }
            throw $e;
        }
    }
}
