<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Domain\Model\ImagingModality;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the ImagingModality aggregate (IC §9; ADR-165 D-C).
 * Used ONLY by the `radiology:define-modality` console command — there is no
 * HTTP bus command for catalog authoring in Rad.1 (the CP.1 / Lab.1 precedent).
 */
interface ImagingModalityRepository extends Repository
{
    public function getById(string $id): ImagingModality;

    public function getByIdForUpdate(string $id): ImagingModality;

    /** The active modality with this `code`, or null (tenant-scoped, RLS). */
    public function findActiveByCode(string $tenantId, string $code): ?ImagingModality;
}
