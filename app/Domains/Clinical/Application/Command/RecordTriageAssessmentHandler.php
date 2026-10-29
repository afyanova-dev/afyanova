<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\TriageAssessmentContextMismatch;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\TriageAssessmentRepository;
use App\Domains\Clinical\Domain\VitalSignsObservationReader;
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
 * Orchestrates `RecordTriageAssessment` (IC §5; ADR-132, ADR-135, ADR-145 D-G).
 *
 * Flow (mirrors `RecordDiagnosisHandler` — a triage assessment is ORIGINATED
 * against an encounter and gated exactly as any clinical-record origination):
 *   1. coarse `clinical.triage.record` — already enforced by the bus
 *      (`TriageAssessmentPolicy::record`).
 *   2. `PatientApi::getPatientSummary` — `NotFound` for unknown / cross-tenant;
 *      `AuthorizationException` if the caller lacks `patient.patients.read`.
 *      Patient-state gate: `ACTIVE` only, `isProvisional` PERMITTED (ADR-119 —
 *      emergency triage of a provisional identity is the norm);
 *      MERGED / DECEASED / INACTIVE -> `PatientNotEligibleForClinicalRecord` (409).
 *   3. `EncounterApi::getEncounterContext` — the E.13 port (`NotFound` for
 *      absent / cross-tenant / out-of-scope encounter).
 *   4. ADR-145 per-encounter-facility refinement — the actor must hold
 *      `clinical.triage.record` at THE ENCOUNTER's facility; otherwise
 *      `NotFound`.
 *   5. patient / encounter consistency — `patientId` must be the encounter's
 *      patient (`TriageAssessmentContextMismatch::patientEncounter`, 409).
 *   6. encounter-state gate — origination requires status in {ARRIVED, ACTIVE,
 *      COMPLETED} (`EncounterNotEligibleForClinicalRecord`, 409; ADR-135 parity,
 *      PLANNED rejected — ADR-145 D-8).
 *   7. optional vitals provenance — when `sourceVitalsObservationId` is
 *      supplied, `VitalSignsObservationReader::summary()` (READ-ONLY,
 *      intra-domain — ADR-140 D-I) must resolve within the tenant (`NotFound`)
 *      and reference the SAME encounter
 *      (`TriageAssessmentContextMismatch::sourceVitals`, 409). NO C.2 mutation,
 *      NO back-reference (ADR-145 D-E).
 *   8. `facility_id` copied from the encounter context; record the assessment;
 *      `occurred_at` = caller value or `now()`, `recorded_at` = `now()`.
 *   9. versioned save + `TriageAssessmentRecorded` to the outbox (one txn). The
 *      `clinical.triage.recorded` audit row (with the backdate `reason`, when
 *      present — never the chief complaint / acuity / consciousness) is written
 *      by the bus `WriteAuditRow` step.
 *
 * `TriageAssessmentRepository` is injected both because the handler needs it and
 * because `AuthorizeCommand` (IC §5.1) reflects it to resolve the
 * `TriageAssessment` aggregate for the class-level `record` check. NO direct
 * access to `pat_*` / `enc_*` tables or aggregates (AGENTS §8; IC §2.1).
 * `VitalSignsObservationReader` is an INTRA-domain read port (both
 * `App\Domains\Clinical`) — not a cross-domain contract (ADR-140 D-I).
 */
final class RecordTriageAssessmentHandler
{
    public function __construct(
        private readonly TriageAssessmentRepository $assessments,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly VitalSignsObservationReader $vitals,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordTriageAssessment $command): RecordTriageAssessmentResult
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->triageAssessmentId);

        if ($encounter->patientId !== $command->patientId) {
            throw TriageAssessmentContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $this->assertEncounterEligible($encounter);

        if ($command->sourceVitalsObservationId !== null) {
            $observation = $this->vitals->summary($command->sourceVitalsObservationId); // C.2 reader, READ-ONLY; RLS-scoped; NotFound if missing / cross-tenant

            if ($observation->encounterId !== $command->encounterId) {
                throw TriageAssessmentContextMismatch::sourceVitals(
                    $command->encounterId,
                    $command->sourceVitalsObservationId,
                    $observation->encounterId,
                );
            }
        }

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $assessment = TriageAssessment::record(
            id: $command->triageAssessmentId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            authorUserId: $command->actorId,
            authoredContext: $command->authoredContext,
            chiefComplaint: $command->chiefComplaint,
            triagePriority: $command->triagePriority,
            acuity: $command->acuity,
            consciousness: $command->consciousness,
            sourceVitalsObservationId: $command->sourceVitalsObservationId,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $this->assessments->save($assessment); // versioned INSERT + TriageAssessmentRecorded -> outbox (one txn)

        return new RecordTriageAssessmentResult((string) $assessment->id);
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
     * ADR-145 refinement — the authenticated clinician / nurse must hold
     * `clinical.triage.record` at the encounter's own facility, not merely at
     * their selected context. An out-of-scope encounter is indistinguishable
     * from an absent one (`NotFound`), never an authorization disclosure (the
     * E.12 R-1 pattern; the `Auth`-resolved actor cannot be spoofed).
     */
    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $triageAssessmentId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordTriageAssessment dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'clinical.triage.record',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(TriageAssessment::class, $triageAssessmentId);
        }
    }

    /**
     * Encounter-state gate (ADR-135 / ADR-145 D-8) — ONLY origination is gated.
     * A triage assessment is recorded only when the encounter is `ARRIVED`,
     * `ACTIVE` or `COMPLETED`. `PLANNED` is rejected (no attendance yet — ADR-135
     * rationale, ADR-138 D10).
     */
    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForClinicalRecord::status($encounter->encounterId, $encounter->status);
        }
    }
}
