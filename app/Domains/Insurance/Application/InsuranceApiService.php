<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application;

use App\Domains\Insurance\Application\Query\EvaluateCoverage;
use App\Domains\Insurance\Application\Query\GetPatientCoverages;
use App\Domains\Insurance\Contracts\Dto\CoverageDecision;
use App\Domains\Insurance\Contracts\Dto\InsuranceCoverageRef;
use App\Domains\Insurance\Contracts\Dto\MemberCoverageSummary;
use App\Domains\Insurance\Contracts\InsuranceApi;
use App\Domains\Insurance\Domain\ValueObject\MemberCoverageStatus;
use App\Shared\Bus\QueryBus;

/**
 * Port implementation (IC §5.2; ADR-201 D-A). Both methods cross the QueryBus —
 * they expose protected coverage data, so the bus's authorization step is
 * never skipped (the `PatientApi::getPatientSummary` precedent). Adds no
 * repository, migration or event.
 */
final class InsuranceApiService implements InsuranceApi
{
    public function __construct(private readonly QueryBus $queryBus) {}

    public function patientCoverages(string $patientId): array
    {
        /** @var list<MemberCoverageSummary> $coverages */
        $coverages = $this->queryBus->dispatch(new GetPatientCoverages($patientId));

        $refs = [];
        foreach ($coverages as $coverage) {
            if ($coverage->status !== MemberCoverageStatus::ACTIVE) {
                continue;
            }
            $refs[] = new InsuranceCoverageRef(
                memberCoverageId: $coverage->memberCoverageId,
                payerId: $coverage->payerId,
                planId: $coverage->planId,
                priority: $coverage->priority,
                memberNumber: $coverage->memberNumber,
            );
        }

        return $refs;
    }

    public function evaluateCoverage(
        string $patientId,
        string $planId,
        string $serviceCategory,
        ?string $chargeAmount = null,
        ?string $onDate = null,
    ): CoverageDecision {
        /** @var CoverageDecision */
        return $this->queryBus->dispatch(new EvaluateCoverage(
            patientId: $patientId,
            planId: $planId,
            serviceCategory: $serviceCategory,
            chargeAmount: $chargeAmount,
            onDate: $onDate,
        ));
    }
}
