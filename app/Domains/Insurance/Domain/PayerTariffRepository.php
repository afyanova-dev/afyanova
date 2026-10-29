<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\PayerTariff;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the payer tariff aggregate (IC §9; ADR-203).
 * The FIRST repository parameter of tariff commands — the bus resolves
 * `PayerTariffPolicy` from it.
 */
interface PayerTariffRepository extends Repository
{
    public function getById(string $id): PayerTariff;

    public function getByIdForUpdate(string $id): PayerTariff;

    /**
     * The single open (CURRENT, `effective_to is null`) tariff for a (contract,
     * category) scope, row-locked for the current transaction. Null when none.
     */
    public function openRowForScopeForUpdate(string $contractId, string $serviceCategory): ?PayerTariff;
}
