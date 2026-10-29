<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one PrescribingDecision (ADR-143 D-Q).
 * Returned by `GetPrescribingDecision` and `GetPatientPrescribingDecisions` via `PrescribingDecisionReader`.
 *
 * NOT an event payload. NOT #[TypeScript] (no PHASE 6 frontend consumer).
 */
final readonly class PrescribingDecisionSummary
{
    public function __construct(
        public string $prescribingDecisionId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $authoredContext,
        public string $medicationName,
        public ?string $medicationCode,
        public ?string $medicationCodeSystem,
        public ?string $dose,
        public ?string $route,
        public ?string $frequency,
        public ?string $duration,
        public ?string $quantity,
        public ?string $dosingInstruction,
        public ?string $therapeuticIntent,
        public ?string $indication,
        public ?string $urgency,
        public string $status,
        public ?string $discontinuedReason,
        public ?string $correctionReason,
        public ?string $supersedesPrescribingDecisionId,
        public ?string $supersededAt,
        public ?string $supersededByPrescribingDecisionId,
        public ?string $clinicalPlanId,
        public ?string $indicationDiagnosisId,
        public ?string $indicationProblemId,
        public string $occurredAt,
        public string $recordedAt,
        // ADR-183 D-H — the prescriber-authored structured quantity, APPENDED.
        // Non-null IFF the prescriber supplied it (ADR-183 D-K); $quantityValue
        // is a decimal:4 numeric string; both null on legacy / "quantity
        // sufficient" decisions.
        public ?string $quantityValue,
        public ?string $quantityUnit,
    ) {}
}
