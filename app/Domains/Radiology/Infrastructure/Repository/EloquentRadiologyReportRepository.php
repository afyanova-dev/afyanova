<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Repository;

use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\Model\RadiologyReportAmendment;
use App\Domains\Radiology\Domain\RadiologyReportRepository;
use App\Shared\Domain\EloquentRepository;
use App\Shared\Domain\NotFound;

/**
 * Eloquent implementation of RadiologyReportRepository (ADR-167 D-B, ADR-168 D-B).
 */
final class EloquentRadiologyReportRepository extends EloquentRepository implements RadiologyReportRepository
{
    protected function model(): string
    {
        return RadiologyReport::class;
    }

    public function getById(string $id): RadiologyReport
    {
        return RadiologyReport::query()->whereKey($id)->first()
            ?? throw NotFound::aggregate(RadiologyReport::class, $id);
    }

    public function getByIdForUpdate(string $id): RadiologyReport
    {
        return RadiologyReport::query()->whereKey($id)->lockForUpdate()->first()
            ?? throw NotFound::aggregate(RadiologyReport::class, $id);
    }

    public function findByStudy(string $studyId): ?RadiologyReport
    {
        return RadiologyReport::query()->where('study_id', $studyId)->first();
    }

    public function nextAmendmentSequence(string $reportId): int
    {
        $max = RadiologyReportAmendment::query()->where('report_id', $reportId)->max('sequence');

        return ((int) $max) + 1;
    }
}
