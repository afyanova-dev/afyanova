<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Domain\Model\ImagingService;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the ImagingService aggregate (IC §9; ADR-165 D-D).
 * Used ONLY by the `radiology:define-imaging-service` console command.
 */
interface ImagingServiceRepository extends Repository
{
    public function getById(string $id): ImagingService;

    public function getByIdForUpdate(string $id): ImagingService;

    /** The active service with this `code`, or null (tenant-scoped, RLS). */
    public function findActiveByCode(string $tenantId, string $code): ?ImagingService;
}
