<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\DiagnosisReader;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\ProblemContextMismatch;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ProblemRepository;
use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `AddProblem` (IC §5; ADR-132, ADR-135, ADR-140 D-O).
 *
 * Flow (mirrors `RecordDiagnosisHandler` — a problem is ORIGINATED against an
 * encounter and gated exactly as any clinical-record origination):
 *   1. coarse `clinical.problems.record` — already enforced by the bus
 *      (`ProblemPolicy::record`).
 *   2. `PatientApi::getPatientSummary` — `NotFound` for unknown / cross-tenant;
 *      `AuthorizationException` if the caller lacks `patient.patients.read`.
 *      Patient-state gate: `ACTIVE` only, `isProvisional` permitted (ADR-119);
 *      MERGED / DECEASED / INACTIVE -> `PatientNotEligibleForClinicalRecord` (409).
 *   3. `EncounterApi::getEncounterContext` — `NotFound` for absent / cross-tenant
 *      / out-of-scope encounter.
 *   4. ADR-140 per-encounter-facility refinement — the actor must hold
 *      `clinical.problems.record` at THE ENCOUNTER's facility; otherwise
 *      `NotFound` (the E.12 R-1 pattern; the `Auth`-resolved actor cannot be
 *      spoofed).
 *   5. patient / encounter consistency — `patientId` must be the encounter's
 *      patient (`ProblemContextMismatch::patientEncounter`, 409).
 *   6. encounter-state gate — origination requires status in {ARRIVED, ACTIVE,
 *      COMPLETED} (`EncounterNotEligibleForClinicalRecord`, 409).
 *   7. optional Diagnosis provenance — when `sourceDiagnosisId` is supplied,
 *      `DiagnosisReader::summary()` (READ-ONLY, intra-domain) must resolve
 *      within the tenant (`NotFound` otherwise) and reference the SAME patient
 *      (`ProblemContextMismatch::sourceDiagnosis`, 409). NO Diagnosis mutation,
 *      NO back-reference (ADR-140 D-I).
 *   8. `facility_id` copied from the encounter context; add the ACTIVE problem;
 *      `occurred_at` = caller value or `now()`, `recorded_at` = `now()`.
 *   9. versioned save + `ProblemAdded` to the outbox (one txn). The
 *      `clinical.problems.added` audit row (with the backdate `reason`, when
 *      present — never the description / code / onset date) is written by the
 *      bus `WriteAuditRow` step.
 *
 * `ProblemRepository` is injected both because the handler needs it and because
 * `AuthorizeCommand` (IC §5.1) reflects it to resolve the `Problem` aggregate
 * for the class-level `record` check. NO direct access to `pat_*` / `enc_*`
 * tables or aggregates (AGENTS §8; IC §2.1). `DiagnosisReader` is an
 * INTRA-domain read port (both `App\Domains\Clinical`) — not a cross-domain
 * contract (ADR-140 D-I).
 */
final class AddProblemHandler
{
    public function __construct(
        private readonly ProblemRepository $problems,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly DiagnosisReader $diagnoses,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AddProblem $command): AddProblemResult
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->problemId);

        if ($encounter->patientId !== $command->patientId) {
            throw ProblemContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $this->assertEncounterEligible($encounter);

        if ($command->sourceDiagnosisId !== null) {
            $diagnosis = $this->diagnoses->summary($command->sourceDiagnosisId); // C.3 DiagnosisReader, READ-ONLY; RLS-scoped; NotFound if missing / cross-tenant

            if ($diagnosis->patientId !== $command->patientId) {
                throw ProblemContextMismatch::sourceDiagnosis(
                    $command->patientId,
                    $command->sourceDiagnosisId,
                    $diagnosis->patientId,
                );
            }
        }

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;
        $onsetDate = $command->onsetDate !== null ? CarbonImmutable::parse($command->onsetDate) : null;

        $problem = Problem::add(
            id: $command->problemId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            authorUserId: $command->actorId,
            coding: $command->coding,
            description: $command->description,
            onsetDate: $onsetDate,
            sourceDiagnosisId: $command->sourceDiagnosisId,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $this->problems->save($problem); // versioned INSERT + ProblemAdded -> outbox (one txn)

        return new AddProblemResult((string) $problem->id);
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
     * ADR-140 D-K refinement — the authenticated clinician must hold
     * `clinical.problems.record` at the encounter's own facility, not merely at
     * their selected context. An out-of-scope encounter is indistinguishable
     * from an absent one (`NotFound`), never an authorization disclosure (the
     * E.12 R-1 pattern; the `Auth`-resolved actor cannot be spoofed).
     */
    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $problemId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('AddProblem dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'clinical.problems.record',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(Problem::class, $problemId);
        }
    }

    /**
     * Encounter-state gate (ADR-135 / ADR-140 D-O) — ONLY origination is gated.
     * A problem is added only when the encounter is `ARRIVED`, `ACTIVE` or
     * `COMPLETED`.
     */
    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForClinicalRecord::status($encounter->encounterId, $encounter->status);
        }
    }
}
