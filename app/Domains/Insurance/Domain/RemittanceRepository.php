<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\Remittance;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Remittance aggregate (IC §9; ADR-205).
 * The FIRST repository parameter of remittance commands — the bus resolves
 * `RemittancePolicy` from it.
 */
interface RemittanceRepository extends Repository
{
    public function getById(string $id): Remittance;

    public function getByIdForUpdate(string $id): Remittance;

    public function getByRemittanceNumber(string $remittanceNumber): ?Remittance;
}
