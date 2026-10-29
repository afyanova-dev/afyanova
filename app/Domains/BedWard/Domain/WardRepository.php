<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain;

use App\Domains\BedWard\Domain\Model\Ward;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for Ward aggregate (ADR-148 D-A).
 */
interface WardRepository extends Repository
{
    public function getById(string $id): Ward;

    public function getByIdForUpdate(string $id): Ward;
}
