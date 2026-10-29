<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Contracts\Dto\RadiologyReportAmendmentSummary;
use App\Domains\Radiology\Contracts\Dto\RadiologyReportSummary;

/**
 * Read-side port for RadiologyReport queries (ADR-167 D-F, ADR-168 D-E).
 * Returns DTOs only — never an Eloquent model (IC §9).
 */
interface RadiologyReportReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the report is absent from the
     * current tenant scope (RLS).
     */
    public function summary(string $reportId): RadiologyReportSummary;

    /** The report for a study, or `NotFound`. */
    public function forStudy(string $studyId): RadiologyReportSummary;

    /**
     * The reporting worklist — non-released reports at a facility, priority
     * order (§77 / §78).
     *
     * @return list<RadiologyReportSummary>
     */
    public function pendingForFacility(string $facilityId): array;

    /**
     * A report's amendment history, sequence order (§96).
     *
     * @return list<RadiologyReportAmendmentSummary>
     */
    public function amendmentsFor(string $reportId): array;
}
