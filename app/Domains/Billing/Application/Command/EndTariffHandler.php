<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Exception\IllegalTariffTransition;
use App\Domains\Billing\Domain\TariffRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * Orchestrates `EndTariff` (ADR-188 D-C). Resolve the scope (null → the
 * tenant-id sentinel) → `openRowForScopeForUpdate` → require an open row and
 * `effectiveTo` strictly after its `effective_from` → `end()` → save.
 */
final class EndTariffHandler
{
    public function __construct(
        private readonly TariffRepository $tariffs,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(EndTariff $command): void
    {
        $facilityId = $command->facilityId ?? $this->context->tenantId();

        $open = $this->tariffs->openRowForScopeForUpdate($command->billingServiceId, $facilityId);

        if ($open === null) {
            throw IllegalTariffTransition::noOpenRow('EndTariff', $command->billingServiceId);
        }

        if (! $command->effectiveTo->gt($open->effective_from)) {
            throw IllegalTariffTransition::endDateNotAfterStart(
                $command->effectiveTo->toDateString(),
                $open->effective_from->toDateString(),
            );
        }

        $open->end($command->effectiveTo);

        $this->tariffs->save($open);
    }
}
