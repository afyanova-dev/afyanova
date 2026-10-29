<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\PayerTariffSummary;

/**
 * Read-side port for payer tariffs (IC §6.2; ADR-203). Returns DTOs only.
 */
interface PayerTariffReader
{
    public function summary(string $tariffId): PayerTariffSummary;

    /**
     * The tariffs for a contract, newest effective_from first, optionally narrowed to one category.
     *
     * @return list<PayerTariffSummary>
     */
    public function forContract(string $contractId, ?string $serviceCategory = null): array;

    /**
     * The tariff effective for a (contract, category) on a date.
     */
    public function effectiveTariffForContract(string $contractId, string $serviceCategory, string $onDate): ?PayerTariffSummary;

    /**
     * The tariff effective for a (payer, category) on a date and facility.
     * Resolves the active contract for the payer and facility, then finds its effective tariff.
     */
    public function effectiveTariffForPayer(string $payerId, string $serviceCategory, string $onDate, ?string $facilityId = null): ?PayerTariffSummary;
}
