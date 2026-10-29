<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\Authorization;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the authorization aggregate (IC §9; ADR-202 D-B). The
 * FIRST repository parameter of the authorization commands — the bus resolves
 * `AuthorizationPolicy` from it.
 */
interface AuthorizationRepository extends Repository
{
    public function getById(string $id): Authorization;

    public function getByIdForUpdate(string $id): Authorization;
}
