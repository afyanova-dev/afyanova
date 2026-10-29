<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Domain\Model\CaseEvent;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for CaseEvent aggregate (ADR-156 D-E).
 */
interface CaseEventRepository extends Repository
{
    public function getById(string $id): CaseEvent;

    public function getByIdForUpdate(string $id): CaseEvent;
}
