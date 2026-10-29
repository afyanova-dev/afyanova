<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one ClinicalPlan (ADR-143 D-Q).
 * Returned by `GetClinicalPlan` and `GetPatientClinicalPlans` via `ClinicalPlanReader`.
 *
 * NOT an event payload. NOT #[TypeScript] (no PHASE 6 frontend consumer).
 */
final readonly class ClinicalPlanSummary
{
    public function __construct(
        public string $planId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $authoredContext,
        public string $planType,
        public string $clinicalIntent,
        public ?string $goals,
        public ?string $plannedActions,
        public ?string $instructions,
        public ?string $followUp,
        public string $status,
        public ?string $closedReason,
        public ?string $indicationDiagnosisId,
        public ?string $indicationProblemId,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
