<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one ClinicalProcedure (ADR-146 D-Q).
 * Returned by `GetProcedure`, `GetEncounterProcedures` and `GetPatientProcedures`
 * via `ClinicalProcedureReader`.
 *
 * NOT an event payload — the events carry identifiers + coded values only
 * (ADR-136 / ADR-146 D-R). NOT `#[TypeScript]`; HTTP workflow controllers
 * project only the authorized fields needed by their Inertia pages.
 *
 * `indication` / `outcomeDetail` / `complications` / `statusReason` (free text)
 * ARE returned to an authorized reader — the `#[ProtectedQuery]` gate plus the
 * ADR-133 facility refinement IS the authorization (the `GetClinicalNote`
 * precedent). `amendments` is the append-only correction trail (ADR-146 D-M) —
 * each entry the raw `clin_procedure_amendments` row shape.
 */
final readonly class ProcedureSummary
{
    /**
     * @param  list<array{amendmentId: string, amendmentType: string, body: string, reason: string, changedFields: list<string>, authorUserId: string, occurredAt: string, recordedAt: string}>  $amendments
     */
    public function __construct(
        public string $procedureId,
        public string $patientId,
        public string $encounterId,
        public string $facilityId,
        public string $authorUserId,
        public ?string $authoredContext,
        public string $procedureNumber,
        public string $status,
        public string $definitionId,
        public int $definitionVersion,
        public ?string $procedureCategory,
        public string $requestedByUserId,
        public ?string $authorizedByUserId,
        public ?string $authorizationBasis,
        public ?string $authorizationProtocolReference,
        public ?string $authorizationProtocolApprovalId,
        public ?string $authorizationProtocolVersion,
        public ?string $performedByUserId,
        public ?string $locationLabel,
        public ?string $site,
        public ?string $laterality,
        public ?string $outcome,
        public ?string $outcomeDetail,
        public ?string $complications,
        public ?string $statusReason,
        public ?string $requestedAt,
        public ?string $authorizedAt,
        public ?string $readyAt,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $sourceDiagnosisId,
        public ?string $sourceClinicalPlanId,
        public ?string $indication,
        public ?string $clinicalNoteId,
        public ?string $financialClearanceRef,
        public ?string $financialPolicyVersion,
        public string $occurredAt,
        public string $recordedAt,
        public array $amendments,
    ) {}
}
