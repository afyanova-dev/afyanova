<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\DiagnosisRepository;
use App\Domains\Clinical\Domain\Exception\DiagnosisContextMismatch;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Model\Diagnosis;
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
 * Orchestrates `RecordDiagnosis` (IC §5; ADR-132, ADR-135, ADR-139).
 *
 * Flow (mirrors `CreateClinicalNoteHandler` / `RecordVitalSignsHandler` —
 * diagnosis origination is gated exactly as any clinical-record origination,
 * ADR-135 / ADR-139):
 *   1. coarse `clinical.diagnoses.record` — already enforced by the bus
 *      (`DiagnosisPolicy::record`).
 *   2. `PatientApi::getPatientSummary` — `NotFound` for unknown / cross-tenant;
 *      `AuthorizationException` if the caller lacks `patient.patients.read`.
 *      Patient-state gate: `ACTIVE` only, `isProvisional` permitted (ADR-119);
 *      MERGED / DECEASED / INACTIVE -> `PatientNotEligibleForClinicalRecord` (409).
 *   3. `EncounterApi::getEncounterContext` — the E.13 port (`NotFound` for
 *      absent / cross-tenant / out-of-scope encounter).
 *   4. ADR-139 per-encounter-facility refinement — the actor must hold
 *      `clinical.diagnoses.record` at THE ENCOUNTER's facility; otherwise
 *      `NotFound`.
 *   5. patient / encounter consistency — `patientId` must be the encounter's
 *      patient (`DiagnosisContextMismatch`, 409).
 *   6. encounter-state gate — origination requires status ∈ {ARRIVED, ACTIVE,
 *      COMPLETED} (`EncounterNotEligibleForClinicalRecord`, 409).
 *   7. `facility_id` copied from the encounter context; record the diagnosis;
 *      `occurred_at` = caller value or `now()`, `recorded_at` = `now()`.
 *   8. versioned save + `DiagnosisRecorded` to the outbox (one txn). The
 *      `clinical.diagnoses.recorded` audit row (with the backdate `reason`, when
 *      present — never the code / description) is written by the bus
 *      `WriteAuditRow` step.
 *
 * `DiagnosisRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to resolve the `Diagnosis`
 * aggregate for the class-level `record` check. NO direct access to `pat_*` /
 * `enc_*` tables or aggregates (AGENTS §8; IC §2.1).
 */
final class RecordDiagnosisHandler
{
    public function __construct(
        private readonly DiagnosisRepository $diagnoses,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordDiagnosis $command): RecordDiagnosisResult
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->diagnosisId);

        if ($encounter->patientId !== $command->patientId) {
            throw DiagnosisContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $this->assertEncounterEligible($encounter);

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $diagnosis = Diagnosis::record(
            id: $command->diagnosisId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            authorUserId: $command->actorId,
            coding: $command->coding,
            description: $command->description,
            clinicalStatus: $command->clinicalStatus,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $this->diagnoses->save($diagnosis); // versioned INSERT + DiagnosisRecorded -> outbox (one txn)

        return new RecordDiagnosisResult((string) $diagnosis->id);
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
     * ADR-139 refinement — the authenticated clinician must hold
     * `clinical.diagnoses.record` at the encounter's own facility, not merely at
     * their selected context. An out-of-scope encounter is indistinguishable
     * from an absent one (`NotFound`), never an authorization disclosure (the
     * E.12 R-1 pattern; the `Auth`-resolved actor cannot be spoofed).
     */
    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $diagnosisId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordDiagnosis dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'clinical.diagnoses.record',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(Diagnosis::class, $diagnosisId);
        }
    }

    /**
     * Encounter-state gate (ADR-135 / ADR-139) — ONLY origination is gated. A
     * diagnosis is recorded only when the encounter is `ARRIVED`, `ACTIVE` or
     * `COMPLETED`.
     */
    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForClinicalRecord::status($encounter->encounterId, $encounter->status);
        }
    }
}
