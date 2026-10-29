<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\BillingServiceRepository;
use App\Domains\Billing\Domain\Exception\BillingServiceInactive;
use App\Domains\Billing\Domain\Exception\TariffChangeConflict;
use App\Domains\Billing\Domain\Exception\TariffEffectiveDateNotAfterCurrent;
use App\Domains\Billing\Domain\Model\Tariff;
use App\Domains\Billing\Domain\TariffRepository;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Orchestrates `SetTariff` (ADR-188 D-C).
 *
 * load the service → require ACTIVE (`BillingServiceInactive`) → resolve the
 * scope (null → the tenant-id sentinel) → `openRowForScopeForUpdate` (the F1
 * row-lock) → if an open row exists, require `effectiveFrom` strictly after it
 * (`TariffEffectiveDateNotAfterCurrent`) and `supersede()` it → insert the new
 * CURRENT row; on the partial-unique violation (a concurrent first `SetTariff`)
 * → `TariffChangeConflict`.
 *
 * `TariffRepository` is the FIRST repository parameter so the bus resolves
 * `BillingCatalogPolicy` (registered for `Tariff`); `BillingServiceRepository`
 * is a later parameter (same module — no cross-domain call).
 */
final class SetTariffHandler
{
    public function __construct(
        private readonly TariffRepository $tariffs,
        private readonly BillingServiceRepository $services,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(SetTariff $command): void
    {
        $service = $this->services->getById($command->billingServiceId);

        if (! $service->isActive()) {
            throw BillingServiceInactive::id($command->billingServiceId);
        }

        $facilityId = $command->facilityId ?? $this->context->tenantId();

        $open = $this->tariffs->openRowForScopeForUpdate($command->billingServiceId, $facilityId);

        if ($open !== null) {
            if (! $command->effectiveFrom->gt($open->effective_from)) {
                throw TariffEffectiveDateNotAfterCurrent::dates(
                    $command->effectiveFrom->toDateString(),
                    $open->effective_from->toDateString(),
                );
            }
            $open->supersede($command->effectiveFrom);
            $this->tariffs->save($open);
        }

        $now = CarbonImmutable::now();

        $tariff = Tariff::open(
            id: $command->tariffId,
            tenantId: $this->context->tenantId(),
            billingServiceId: $command->billingServiceId,
            facilityId: $facilityId,
            amount: $command->amount,
            currency: $command->currency,
            effectiveFrom: $command->effectiveFrom,
            occurredAt: $now,
            recordedAt: $now,
        );

        try {
            $this->tariffs->save($tariff);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'bill_tariffs_one_open_per_scope')) {
                throw TariffChangeConflict::forScope($command->billingServiceId);
            }
            throw $e;
        }
    }
}
