<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain;

use App\Domains\Billing\Domain\Model\WriteOff;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the write-off aggregate (IC §9; ADR-191 D7-A). The FIRST
 * repository parameter of the write-off commands — the bus resolves
 * `WriteOffPolicy` from it.
 */
interface WriteOffRepository extends Repository
{
    public function getById(string $id): WriteOff;

    public function getByIdForUpdate(string $id): WriteOff;

    public function exists(string $id): bool;
}
