<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts;

use App\Domains\Insurance\Contracts\Dto\CoverageDecision;
use App\Domains\Insurance\Contracts\Dto\InsuranceCoverageRef;

/**
 * The ONLY Insurance & Payer surface other modules may import (IC §2.1;
 * ADR-198 D-I — deferred; published at Ins.4, ADR-201 D-A, with the minimum
 * the Billing estimate seam needs). Grows per concrete consumer — do not add a
 * method without a caller (IC §26).
 *
 * Both methods are PROTECTED reads — they dispatch a `#[ProtectedQuery]` and
 * the caller's actor must hold `insurance.access` (coverage data is sensitive,
 * §154 / §283; the `PatientApi::getPatientSummary` precedent).
 */
interface InsuranceApi
{
    /**
     * The patient's ACTIVE member coverages, so a consumer can pick one.
     * Dispatches `GetPatientCoverages` (Ins.2).
     *
     * @return list<InsuranceCoverageRef>
     */
    public function patientCoverages(string $patientId): array;

    /**
     * Evaluate a plan's coverage of a service category for the patient.
     * Dispatches `EvaluateCoverage` (Ins.3). `chargeAmount` (optional) turns on
     * the illustrative payer/patient split estimate; `onDate` (optional)
     * defaults to today.
     */
    public function evaluateCoverage(
        string $patientId,
        string $planId,
        string $serviceCategory,
        ?string $chargeAmount = null,
        ?string $onDate = null,
    ): CoverageDecision;
}
