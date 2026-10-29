<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\PayerContract;
use App\Shared\Domain\Repository;
use Carbon\CarbonImmutable;

/**
 * Write-side port for the payer contract aggregate (IC §9; ADR-203).
 * The FIRST repository parameter of contract commands — the bus resolves
 * `PayerContractPolicy` from it.
 */
interface PayerContractRepository extends Repository
{
    public function getById(string $id): PayerContract;

    public function getByIdForUpdate(string $id): PayerContract;

    /**
     * @return list<PayerContract>
     */
    public function findActivePastExpiry(CarbonImmutable $onDate, int $limit = 100): array;
}
