<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Shared\Domain\Repository;

/**
 * Write-side repository port for the RadiologyReport aggregate (ADR-167 D-B).
 */
interface RadiologyReportRepository extends Repository
{
    public function getById(string $id): RadiologyReport;

    public function getByIdForUpdate(string $id): RadiologyReport;

    /** The report for a study, or null (the `CreateRadiologyReport` one-per-study guard). */
    public function findByStudy(string $studyId): ?RadiologyReport;

    /** The next 1-based amendment sequence for a report (ADR-168 D-B). */
    public function nextAmendmentSequence(string $reportId): int;
}
