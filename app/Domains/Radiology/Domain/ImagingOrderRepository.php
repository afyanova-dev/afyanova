<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the ImagingOrder aggregate (ADR-165 D-E).
 */
interface ImagingOrderRepository extends Repository
{
    public function getById(string $id): ImagingOrder;

    public function getByIdForUpdate(string $id): ImagingOrder;
}
