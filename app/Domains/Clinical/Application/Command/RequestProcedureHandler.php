<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ClinicalPlanReader;
use App\Domains\Clinical\Domain\ClinicalProcedureRepository;
use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\ProcedureCapabilityUnavailable;
use App\Domains\Clinical\Domain\Exception\ProcedureContextMismatch;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Domains\Clinical\Domain\ProcedureDefinitionReader;
use App\Domains\Clinical\Domain\ProcedureProtocolApprovalReader;
use App\Domains\Clinical\Domain\Service\ClinicalProcedureLifecycle;
use App\Domains\Clinical\Infrastructure\Sequence\ProcedureNumberSequence;
use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `RequestProcedure` (IC §5; ADR-132, ADR-135, ADR-146 D-G).
 *
 * Flow (the `RecordTriageAssessmentHandler` / `AddProblemHandler` origination
 * precedent, extended with the ADR-120 capability gate and two provenance
 * checks):
 *   1. coarse `clinical.procedures.request` — already enforced by the bus
 *      (`ProcedurePolicy::request`).
 *   2. `PatientApi::getPatientSummary` — patient-state gate: `ACTIVE` only,
 *      `isProvisional` PERMITTED (ADR-119); MERGED / DECEASED / INACTIVE ->
 *      `PatientNotEligibleForClinicalRecord` (409).
 *   3. `EncounterApi::getEncounterContext` — `NotFound` for absent /
 *      cross-tenant / out-of-scope encounter.
 *   4. ADR-133 per-encounter-facility refinement -> `NotFound` if the actor
 *      lacks `clinical.procedures.request` at THE ENCOUNTER's facility.
 *   5. patient / encounter consistency -> `ProcedureContextMismatch::patientEncounter`.
 *   6. encounter-state gate — {ARRIVED, ACTIVE, COMPLETED}, PLANNED rejected ->
 *      `EncounterNotEligibleForClinicalRecord`.
 *   7. `ProcedureDefinitionReader::summary()` (intra-domain, READ-ONLY) resolves
 *      the definition; if `required_capability_code` is set,
 *      `FacilityCapabilities::has()` at the encounter's facility ->
 *      `ProcedureCapabilityUnavailable` (409) if not enabled (13 §22, ADR-120).
 *   8. optional provenance — `sourceDiagnosisId` via `DiagnosisReader::summary()`
 *      must be on the SAME encounter; `sourceClinicalPlanId` via
 *      `ClinicalPlanReader::summary()` must belong to the SAME patient (no
 *      status / encounter gate — a `ClinicalPlan` is longitudinal, ADR-146
 *      D-K / M7). Both READ-ONLY, intra-domain.
 *   9. `ProcedureNumberSequence::next()` allocates `PROC-%08d` (ADR-137).
 *   10. `facility_id` from the encounter context; request the procedure;
 *       `occurred_at` = caller value or `now()`, `recorded_at` = `now()`.
 *   11. versioned save + `ProcedureRequested` to the outbox (one txn). The
 *       `procedures.requested` audit row (with the backdate `reason`, when
 *       present) is written by the bus `WriteAuditRow` step.
 *
 * `ClinicalProcedureRepository` is injected both because the handler needs it
 * and because `AuthorizeCommand` (IC §5.1) reflects it to resolve the
 * `ClinicalProcedure` aggregate for the class-level `request` check. NO direct
 * access to `pat_*` / `enc_*` tables or aggregates (AGENTS §8; IC §2.1).
 * `ProcedureDefinitionReader` / `DiagnosisReader` / `ClinicalPlanReader` are
 * INTRA-domain read ports (all `App\Domains\Clinical`) — not cross-domain
 * contracts.
 */
final class RequestProcedureHandler
{
    public function __construct(
        private readonly ClinicalProcedureRepository $procedures,
        private readonly ProcedureDefinitionReader $definitions,
        private readonly ProcedureProtocolApprovalReader $protocolApprovals,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly DiagnosisReader $diagnoses,
        private readonly ClinicalPlanReader $plans,
        private readonly FacilityCapabilities $capabilities,
        private readonly ProcedureNumberSequence $numbers,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
        private readonly ClinicalProcedureLifecycle $lifecycle,
    ) {}

    public function __invoke(RequestProcedure $command): RequestProcedureResult
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility(
            $encounter,
            $command->procedureId,
            $command->protocolReference !== null,
        );

        if ($encounter->patientId !== $command->patientId) {
            throw ProcedureContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $this->assertEncounterEligible($encounter);

        $definition = $this->definitions->summary($command->definitionId); // NotFound if missing / cross-tenant

        $protocolApproval = $command->protocolReference !== null
            ? $this->protocolApprovals->activeForDefinition($encounter->facilityId, $definition->definitionId)
            : null;
        if ($command->protocolReference !== null
            && ($protocolApproval === null
                || $protocolApproval->definitionVersion !== $definition->version
                || ! hash_equals($protocolApproval->protocolReference, trim($command->protocolReference)))) {
            throw new \InvalidArgumentException('This procedure does not have a current facility protocol approval.');
        }

        if ($definition->requiredCapabilityCode !== null
            && ! $this->capabilities->has($encounter->facilityId, $definition->requiredCapabilityCode)) {
            throw ProcedureCapabilityUnavailable::forDefinition($encounter->facilityId, $definition->requiredCapabilityCode);
        }

        if ($command->sourceDiagnosisId !== null) {
            $diagnosis = $this->diagnoses->summary($command->sourceDiagnosisId); // C.3 reader, READ-ONLY; NotFound if missing / cross-tenant

            if ($diagnosis->encounterId !== $command->encounterId) {
                throw ProcedureContextMismatch::sourceDiagnosis(
                    $command->encounterId,
                    $command->sourceDiagnosisId,
                    $diagnosis->encounterId,
                );
            }
        }

        if ($command->sourceClinicalPlanId !== null) {
            $plan = $this->plans->summary($command->sourceClinicalPlanId); // C.7 reader, READ-ONLY; NotFound if missing / cross-tenant

            if ($plan->patientId !== $command->patientId) {
                throw ProcedureContextMismatch::sourceClinicalPlan($command->sourceClinicalPlanId);
            }
        }

        $procedureNumber = $this->numbers->next($this->context->tenantId());

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $procedure = ClinicalProcedure::request(
            id: $command->procedureId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            authorUserId: $command->actorId,
            authoredContext: $command->authoredContext,
            procedureNumber: $procedureNumber,
            definitionId: $definition->definitionId,
            definitionVersion: $definition->version,
            procedureCategory: $definition->category,
            indication: $command->indication,
            sourceDiagnosisId: $command->sourceDiagnosisId,
            sourceClinicalPlanId: $command->sourceClinicalPlanId,
            financialClearanceRef: $command->financialClearanceRef,
            financialPolicyVersion: null,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        if ($protocolApproval !== null) {
            $this->lifecycle->assertCanAuthorize($procedure->status);
            $procedure->authorize(
                authorizedByUserId: $command->actorId,
                protocolReference: $protocolApproval->protocolReference,
                protocolApprovalId: $protocolApproval->approvalId,
                protocolVersion: $protocolApproval->protocolVersion,
            );
        }

        $this->procedures->save($procedure); // request + optional protocol authorization events commit together

        return new RequestProcedureResult((string) $procedure->id, $procedureNumber);
    }

    private function assertPatientEligible(PatientSummary $patient): void
    {
        if ($patient->status === 'MERGED') {
            throw PatientNotEligibleForClinicalRecord::merged($patient->patientId);
        }

        if ($patient->status === 'DECEASED') {
            throw PatientNotEligibleForClinicalRecord::deceased($patient->patientId);
        }

        if ($patient->status === 'INACTIVE') {
            throw PatientNotEligibleForClinicalRecord::inactive($patient->patientId);
        }
    }

    /**
     * ADR-133 refinement — the authenticated clinician / nurse must hold
     * `clinical.procedures.request` at the encounter's own facility, not
     * merely at their selected context. An out-of-scope encounter is
     * indistinguishable from an absent one (`NotFound`), never an
     * authorization disclosure (the E.12 R-1 pattern).
     */
    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $procedureId, bool $protocolRequest): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RequestProcedure dispatched without an authenticated user (IC §5).');
        }

        $permission = $protocolRequest
            ? 'clinical.procedures.protocol_request'
            : 'clinical.procedures.request';
        if (! in_array(
            $permission,
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(ClinicalProcedure::class, $procedureId);
        }
    }

    /**
     * Encounter-state gate (ADR-135 / ADR-146 D-G) — ONLY origination is
     * gated. A procedure is requested only when the encounter is `ARRIVED`,
     * `ACTIVE` or `COMPLETED`.
     */
    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForClinicalRecord::status($encounter->encounterId, $encounter->status);
        }
    }
}
