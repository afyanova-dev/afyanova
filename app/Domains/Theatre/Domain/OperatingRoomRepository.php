<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for OperatingRoom aggregate (ADR-150 D-C).
 */
interface OperatingRoomRepository extends Repository
{
    public function getById(string $id): OperatingRoom;

    public function getByIdForUpdate(string $id): OperatingRoom;
}
