<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\PayerContractSummary;
use Carbon\CarbonImmutable;

/**
 * Read-side port for payer contracts (IC §6.2; ADR-203).
 */
interface PayerContractReader
{
    public function summary(string $contractId): PayerContractSummary;

    /**
     * @return list<PayerContractSummary>
     */
    public function forPayer(string $payerId, ?string $status = null): array;

    /**
     * Finds the active contract effective on the given date and facility scope.
     */
    public function activeContractFor(string $payerId, CarbonImmutable $onDate, ?string $facilityId = null): ?PayerContractSummary;
}
