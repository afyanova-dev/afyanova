<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application;

use App\Domains\Clinical\Application\Command\RequestProcedure;
use App\Domains\Clinical\Application\Query\GetEncounterClinicalNotes;
use App\Domains\Clinical\Application\Query\GetEncounterDiagnoses;
use App\Domains\Clinical\Application\Query\GetEncounterProcedures;
use App\Domains\Clinical\Application\Query\GetEncounterTriageAssessments;
use App\Domains\Clinical\Application\Query\GetPatientAllergies;
use App\Domains\Clinical\Application\Query\GetPatientClinicalPlans;
use App\Domains\Clinical\Application\Query\GetPatientPrescribingDecisions;
use App\Domains\Clinical\Application\Query\GetPatientProblems;
use App\Domains\Clinical\Application\Query\GetPatientProcedures;
use App\Domains\Clinical\Application\Query\GetPatientVitalSigns;
use App\Domains\Clinical\Application\Query\GetPrescribingDecision;
use App\Domains\Clinical\Application\Query\GetProcedureDefinitions;
use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Clinical\Contracts\Dto\ClinicalNoteSummary;
use App\Domains\Clinical\Contracts\Dto\ClinicalPlanSummary;
use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;
use App\Domains\Clinical\Contracts\Dto\EncounterDocumentationStatus;
use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Clinical\Contracts\Dto\ProblemSummary;
use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;
use App\Domains\Clinical\Domain\ClinicalNoteReader;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\ProcedureProtocolApprovalReader;
use App\Domains\Clinical\Domain\TriageAssessmentReader;
use App\Domains\Clinical\Domain\VitalSignsObservationReader;
use App\Domains\Clinical\Infrastructure\Reader\ProcedureMaterialReader;
use App\Domains\Clinical\Infrastructure\Writer\ProcedureProtocolApprovalWriter;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Thin port implementation (IC §5.2). `clinicalNoteExists` is an unprotected
 * reference-style read — it delegates straight to the Reader (IC §6.2's
 * reference-read carve-out: no bus ceremony for data that carries no PHI),
 * matching `PatientApiService::patientExists` / `EncounterApiService::
 * encounterExists`.
 *
 * `getPrescribingDecision` (ADR-143 D-Q; ADR-181 D-B) crosses the QueryBus via
 * the existing `GetPrescribingDecision` `#[ProtectedQuery]` — it returns the
 * full clinical content, so the bus's authorization + the
 * `GetPrescribingDecisionHandler` originating-facility refinement must never be
 * skipped (the `EncounterApiService::getEncounterContext` precedent). It adds
 * no new query, reader, policy, repository, migration or event — pure delegation.
 */
final class ClinicalApiService implements ClinicalApi
{
    public function __construct(
        private readonly ClinicalNoteReader $reader,
        private readonly DiagnosisReader $diagnoses,
        private readonly CommandBus $commandBus,
        private readonly QueryBus $queryBus,
        private readonly VitalSignsObservationReader $vitalsReader,
        private readonly TriageAssessmentReader $triageReader,
        private readonly ScopeChecker $scope,
        private readonly ProcedureProtocolApprovalReader $protocolApprovals,
        private readonly ProcedureProtocolApprovalWriter $protocolApprovalWriter,
        private readonly ProcedureMaterialReader $procedureMaterials,
        private readonly TenantContext $context,
        private readonly ClinicalDocumentationDraftService $drafts,
    ) {}

    public function clinicalNoteExists(string $clinicalNoteId): bool
    {
        return $this->reader->exists($clinicalNoteId);
    }

    public function getPrescribingDecision(string $prescribingDecisionId): PrescribingDecisionSummary
    {
        /** @var PrescribingDecisionSummary $summary */
        $summary = $this->queryBus->dispatch(new GetPrescribingDecision($prescribingDecisionId));

        return $summary;
    }

    public function getEncounterClinicalNotes(string $encounterId): array
    {
        /** @var list<ClinicalNoteSummary> $notes */
        $notes = $this->queryBus->dispatch(new GetEncounterClinicalNotes($encounterId));

        return $notes;
    }

    public function getEncounterDiagnoses(string $encounterId): array
    {
        /** @var list<DiagnosisSummary> $diagnoses */
        $diagnoses = $this->queryBus->dispatch(new GetEncounterDiagnoses($encounterId));

        return $diagnoses;
    }

    public function getPatientPrescribingDecisions(string $patientId): array
    {
        /** @var list<PrescribingDecisionSummary> $decisions */
        $decisions = $this->queryBus->dispatch(new GetPatientPrescribingDecisions($patientId));

        return $decisions;
    }

    public function getActiveDiagnosisCatalog(): array
    {
        return $this->diagnoses->activeCatalog();
    }

    public function getDocumentationDraft(
        string $tenantId,
        string $facilityId,
        string $userId,
        string $encounterId,
        string $section,
    ): ?array {
        $user = Auth::user();
        if (! $user instanceof User
            || (string) $user->getAuthIdentifier() !== $userId
            || $this->context->tenantId() !== $tenantId
            || $this->context->facilityId() !== $facilityId) {
            throw new RuntimeException('Documentation drafts require the authenticated author in the active facility context.');
        }

        $permissions = $this->scope->effectivePermissions($user, $facilityId);
        $canReadDraft = match ($section) {
            'NOTE' => in_array('clinical.notes.create', $permissions, true)
                && in_array('clinical.consultations.create', $permissions, true)
                && in_array('encounter.encounters.read', $permissions, true)
                && in_array('patient.patients.read', $permissions, true),
            'DIAGNOSIS' => in_array('clinical.diagnoses.record', $permissions, true)
                && in_array('encounter.encounters.read', $permissions, true)
                && in_array('patient.patients.read', $permissions, true),
            'PLAN' => in_array('clinical.plans.record', $permissions, true)
                && in_array('encounter.encounters.read', $permissions, true)
                && in_array('patient.patients.read', $permissions, true),
            default => false,
        };

        if (! $canReadDraft) {
            throw new RuntimeException('Documentation drafts require the matching clinical workflow permissions.');
        }

        return $this->drafts->find($tenantId, $facilityId, $userId, $encounterId, $section);
    }

    public function getPatientAllergies(
        string $patientId,
        ?string $clinicalStatus = null,
        ?string $verificationStatus = null,
    ): array {
        /** @var list<AllergySummary> $allergies */
        $allergies = $this->queryBus->dispatch(new GetPatientAllergies(
            patientId: $patientId,
            clinicalStatus: $clinicalStatus,
            verificationStatus: $verificationStatus,
        ));

        return $allergies;
    }

    public function getPatientProblems(string $patientId, ?string $status = null): array
    {
        /** @var list<ProblemSummary> $problems */
        $problems = $this->queryBus->dispatch(new GetPatientProblems($patientId, $status));

        return $problems;
    }

    public function getPatientProcedures(string $patientId): array
    {
        /** @var list<ProcedureSummary> $procedures */
        $procedures = $this->queryBus->dispatch(new GetPatientProcedures($patientId));

        return $procedures;
    }

    public function getPatientClinicalPlans(string $patientId): array
    {
        /** @var list<ClinicalPlanSummary> $plans */
        $plans = $this->queryBus->dispatch(new GetPatientClinicalPlans($patientId));

        return $plans;
    }

    public function getPatientVitalSigns(string $patientId): array
    {
        /** @var list<VitalSignsObservationSummary> $observations */
        $observations = $this->queryBus->dispatch(new GetPatientVitalSigns($patientId));

        return $observations;
    }

    public function getEncounterTriageAssessments(string $encounterId, bool $currentOnly = false): array
    {
        /** @var list<TriageAssessmentSummary> $assessments */
        $assessments = $this->queryBus->dispatch(new GetEncounterTriageAssessments($encounterId, $currentOnly));

        return $assessments;
    }

    public function getEncounterProcedures(string $encounterId): array
    {
        /** @var list<ProcedureSummary> $procedures */
        $procedures = $this->queryBus->dispatch(new GetEncounterProcedures($encounterId));

        return $procedures;
    }

    public function requestProtocolProcedure(
        string $procedureId,
        string $actorId,
        string $patientId,
        string $encounterId,
        string $definitionId,
        ?string $indication,
        string $protocolReference,
    ): void {
        $this->commandBus->dispatch(new RequestProcedure(
            procedureId: $procedureId,
            actorId: $actorId,
            patientId: $patientId,
            encounterId: $encounterId,
            definitionId: $definitionId,
            indication: $indication,
            authoredContext: 'NURSING',
            protocolReference: $protocolReference,
        ));
    }

    public function getEncounterDocumentationStatuses(string $facilityId, array $encounterIds): array
    {
        if (count($encounterIds) > 900 || array_filter($encounterIds, static fn (string $id): bool => ! Str::isUuid($id)) !== []) {
            throw new InvalidArgumentException('Encounter documentation status requires at most 900 UUID encounter ids.');
        }

        $encounterIds = array_values(array_unique($encounterIds));
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new RuntimeException('Encounter documentation status requested without an authenticated user.');
        }

        $permissions = $this->facilityIsInCurrentContext($user, $facilityId)
            ? $this->scope->effectivePermissions($user, $facilityId)
            : [];
        $canReadStatus = in_array('clinical.documentation_status.read', $permissions, true);
        $canReadVitals = $canReadStatus || in_array('clinical.vitals.read', $permissions, true);
        $canReadTriage = $canReadStatus || in_array('clinical.triage.read', $permissions, true);

        $vitalsEncounterIds = $canReadVitals
            ? array_fill_keys($this->vitalsReader->recordedEncounterIds($encounterIds, [$facilityId]), true)
            : [];
        $triageEncounterIds = $canReadTriage
            ? array_fill_keys($this->triageReader->recordedEncounterIds($encounterIds, [$facilityId]), true)
            : [];

        return array_map(
            static fn (string $encounterId): EncounterDocumentationStatus => new EncounterDocumentationStatus(
                encounterId: $encounterId,
                vitalsRecorded: $canReadVitals ? isset($vitalsEncounterIds[$encounterId]) : null,
                triageRecorded: $canReadTriage ? isset($triageEncounterIds[$encounterId]) : null,
            ),
            $encounterIds,
        );
    }

    public function getProcedureDefinitions(bool $activeOnly = true): array
    {
        /** @var list<ProcedureDefinitionSummary> $definitions */
        $definitions = $this->queryBus->dispatch(new GetProcedureDefinitions(active: $activeOnly));

        return $definitions;
    }

    public function getDirectProcedureProtocolApprovals(string $facilityId): array
    {
        $user = Auth::user();
        if (! $user instanceof User
            || ! $this->facilityIsInCurrentContext($user, $facilityId)
            || ! in_array('clinical.procedures.read', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw new RuntimeException('Direct procedure protocol approvals require procedure read access at the facility.');
        }

        return $this->protocolApprovals->activeForFacility($facilityId);
    }

    public function getProcedureMaterials(string $tenantId, string $facilityId, string $procedureId): array
    {
        $user = Auth::user();
        if (! $user instanceof User
            || ! $this->facilityIsInCurrentContext($user, $facilityId)
            || ! in_array('clinical.procedures.read', $this->scope->effectivePermissions($user, $facilityId), true)) {
            throw new RuntimeException('Procedure materials require procedure read access at the facility.');
        }

        return $this->procedureMaterials->forProcedure($tenantId, $facilityId, $procedureId);
    }

    public function approveDirectProcedureProtocol(
        string $tenantId,
        string $facilityId,
        string $definitionId,
        int $definitionVersion,
        string $protocolReference,
        string $protocolVersion,
        string $actorId,
    ): void {
        $this->assertCanManageProtocolApproval($facilityId, $actorId);
        $this->protocolApprovalWriter->approve(
            $tenantId,
            $facilityId,
            $definitionId,
            $definitionVersion,
            $protocolReference,
            $protocolVersion,
            $actorId,
        );
    }

    public function revokeDirectProcedureProtocolApproval(
        string $tenantId,
        string $facilityId,
        string $approvalId,
        string $reason,
        string $actorId,
    ): void {
        $this->assertCanManageProtocolApproval($facilityId, $actorId);
        $this->protocolApprovalWriter->revoke($tenantId, $facilityId, $approvalId, $reason, $actorId);
    }

    private function assertCanManageProtocolApproval(string $facilityId, string $actorId): void
    {
        $user = Auth::user();
        if (! $user instanceof User
            || (string) $user->getAuthIdentifier() !== $actorId
            || ! $this->facilityIsInCurrentContext($user, $facilityId)) {
            throw new RuntimeException('Protocol approval management requires an authenticated facility actor.');
        }

        $permissions = $this->scope->effectivePermissions($user, $facilityId);
        if (! in_array('clinical.procedures.protocol_manage', $permissions, true)
            || ! in_array('clinical.procedures.read', $permissions, true)) {
            throw new RuntimeException('Protocol approval management requires clinical procedure governance access.');
        }
    }

    private function facilityIsInCurrentContext(User $user, string $facilityId): bool
    {
        return $this->context->facilityId() === $facilityId
            || ($this->context->isGeneral() && $this->scope->withinFacilityScope($user, $facilityId));
    }
}
