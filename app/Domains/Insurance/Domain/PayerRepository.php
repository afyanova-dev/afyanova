<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\Payer;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the payer aggregate (IC §9; ADR-198 D-G). The FIRST
 * repository parameter of the payer commands — the bus resolves `PayerPolicy`
 * from it.
 */
interface PayerRepository extends Repository
{
    public function getById(string $id): Payer;

    public function getByIdForUpdate(string $id): Payer;

    /** True if a payer with this code exists in the current tenant scope. */
    public function existsByCode(string $code): bool;
}
