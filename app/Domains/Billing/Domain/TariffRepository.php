<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\Tariff;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the tariff aggregate (IC §9; ADR-188 D-B). The FIRST
 * repository parameter of `SetTariff` / `EndTariff` — the bus resolves
 * `BillingCatalogPolicy` from it (registered for `Tariff` too).
 */
interface TariffRepository extends Repository
{
    public function getById(string $id): Tariff;

    public function getByIdForUpdate(string $id): Tariff;

    /** The current open (`effective_to IS NULL`) row for a scope, or null. */
    public function openRowForScope(string $billingServiceId, string $facilityId): ?Tariff;

    /** Like {@see openRowForScope()} but `lockForUpdate()` — the supersession guard (ADR-188 F1). */
    public function openRowForScopeForUpdate(string $billingServiceId, string $facilityId): ?Tariff;
}
