<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Domain\Model\RecoveryHandoff;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for RecoveryHandoff aggregate (ADR-156 D-D).
 */
interface RecoveryHandoffRepository extends Repository
{
    public function getById(string $id): RecoveryHandoff;

    public function getByIdForUpdate(string $id): RecoveryHandoff;
}
