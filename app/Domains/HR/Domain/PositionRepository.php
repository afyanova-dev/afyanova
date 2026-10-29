<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\Position;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Position aggregate (25-hr-and-workforce-domain.md §4; ADR-206).
 */
interface PositionRepository extends Repository
{
    public function getById(string $id): Position;

    public function getByIdForUpdate(string $id): Position;

    public function getByCode(string $code): Position;

    public function existsById(string $id): bool;

    public function existsByCode(string $code): bool;
}
