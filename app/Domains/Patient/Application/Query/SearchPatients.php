<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Search\PatientSearchCriteria;
use App\Shared\Bus\ProtectedQuery;

/**
 * Find an existing patient before registration (10-patient-domain.md §16, §131;
 * ADR-123, ADR-129). Protected — `PatientPolicy::search` on the existing
 * `patient.patients.read` key (ADR-129 F). Results are restricted to the
 * server-resolved active facility; tenant-wide identity resolution belongs in
 * a separate, explicitly authorized workflow.
 *
 * The HTTP route + rate limiter are PHASE 16 (ADR-123 / ADR-124).
 */
#[ProtectedQuery(ability: 'search', subjectType: Patient::class)]
final readonly class SearchPatients
{
    /** ADR-129 — IC §6.3 mandatory pagination cap. */
    public const MAX_PER_PAGE = 100;

    public PatientSearchCriteria $criteria;

    public int $page;

    public int $perPage;

    public function __construct(
        ?string $patientNumber = null,
        ?string $nationalId = null,
        ?string $insuranceId = null,
        ?string $phone = null,
        ?string $nameFragment = null,
        ?string $dateOfBirth = null,
        int $page = 1,
        int $perPage = 25,
    ) {
        $this->criteria = PatientSearchCriteria::fromInput(
            $patientNumber,
            $nationalId,
            $insuranceId,
            $phone,
            $nameFragment,
            $dateOfBirth,
        );

        $this->page = max(1, $page);
        $this->perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
    }
}
