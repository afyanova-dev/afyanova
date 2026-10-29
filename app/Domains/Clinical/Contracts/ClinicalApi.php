<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts;

use App\Domains\Clinical\Contracts\Dto\EncounterDocumentationStatus;
use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Domains\Clinical\Contracts\Dto\ProcedureProtocolApprovalSummary;

/**
 * The ONLY Clinical surface other modules may import (IC §2.1; ADR-131).
 * Published at C.1 with the minimum a concrete consumer needs; grows per
 * concrete consumer — do not add a method without a caller (IC §26).
 *
 * `clinicalNoteExists` (C.1) is an unprotected existence check.
 * `getPrescribingDecision` (ADR-143 D-Q anticipated it "for Pharmacy's PHASE 9
 * increment") is the first PROTECTED read — its concrete caller is Pharmacy's
 * `AcceptPrescriptionHandler` (ADR-181 D-B).
 */
interface ClinicalApi
{
    /** Unprotected existence check — no PHI exposed (mere existence). */
    public function clinicalNoteExists(string $clinicalNoteId): bool;

    /**
     * One prescribing decision's full clinical content (ADR-143 D-Q; ADR-181
     * D-B). PROTECTED — delegates to the `GetPrescribingDecision`
     * `#[ProtectedQuery]` via the QueryBus, so the acting user must hold
     * `clinical.prescribing.read` and be scoped to the decision's ORIGINATING
     * facility (ADR-143 D-P). Throws `App\Shared\Domain\NotFound` when the
     * decision is absent from the caller's tenant scope OR outside the caller's
     * facility scope (never an authorization disclosure). Returns the existing
     * `PrescribingDecisionSummary` unchanged — "the gate IS the authorization".
     */
    public function getPrescribingDecision(string $prescribingDecisionId): PrescribingDecisionSummary;

    /**
     * Return only recorded/not-recorded indicators for encounters at a facility.
     * Each indicator is null when the caller lacks its corresponding read
     * permission; clinical values are never returned by this status surface.
     *
     * @param  list<string>  $encounterIds
     * @return list<EncounterDocumentationStatus>
     */
    public function getEncounterDocumentationStatuses(string $facilityId, array $encounterIds): array;

    /**
     * Protected tenant-scoped procedure catalog read for service routing
     * administration. The caller must hold `clinical.procedures.read`.
     *
     * @return list<ProcedureDefinitionSummary>
     */
    public function getProcedureDefinitions(bool $activeOnly = true): array;

    /**
     * Active, facility-scoped protocol approvals for direct procedure check-in.
     *
     * @return list<ProcedureProtocolApprovalSummary>
     */
    public function getDirectProcedureProtocolApprovals(string $facilityId): array;

    /**
     * @return list<array{materialRef: string, quantity: string, unit: string|null, batchRef: string|null, recordedAt: string}>
     */
    public function getProcedureMaterials(string $tenantId, string $facilityId, string $procedureId): array;

    public function approveDirectProcedureProtocol(
        string $tenantId,
        string $facilityId,
        string $definitionId,
        int $definitionVersion,
        string $protocolReference,
        string $protocolVersion,
        string $actorId,
    ): void;

    public function revokeDirectProcedureProtocolApproval(
        string $tenantId,
        string $facilityId,
        string $approvalId,
        string $reason,
        string $actorId,
    ): void;
}
