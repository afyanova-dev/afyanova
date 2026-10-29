<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Domain\Model\Person;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Person aggregate (25-hr-and-workforce-domain.md §2; ADR-206).
 */
interface PersonRepository extends Repository
{
    public function getById(string $id): Person;

    public function getByIdForUpdate(string $id): Person;

    public function existsById(string $id): bool;

    public function existsByNationalId(string $nationalId): bool;
}
