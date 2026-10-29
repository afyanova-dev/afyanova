<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the ImagingStudy aggregate (ADR-166 D-B).
 */
interface ImagingStudyRepository extends Repository
{
    public function getById(string $id): ImagingStudy;

    public function getByIdForUpdate(string $id): ImagingStudy;

    /**
     * The active (SCHEDULED / READY / IN_PROGRESS) study for an order item, or
     * null — the `ScheduleImagingStudy` one-active-study-per-item guard (§106).
     */
    public function activeForOrderItem(string $orderItemId): ?ImagingStudy;
}
