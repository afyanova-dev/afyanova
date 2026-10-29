<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\Cadre;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Cadre aggregate (25-hr-and-workforce-domain.md §4; ADR-206).
 */
interface CadreRepository extends Repository
{
    public function getById(string $id): Cadre;

    public function getByIdForUpdate(string $id): Cadre;

    public function getByCode(string $code): Cadre;

    public function existsById(string $id): bool;

    public function existsByCode(string $code): bool;
}
