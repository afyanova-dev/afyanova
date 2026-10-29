<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\VitalSignsContextMismatch;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\VitalSignsObservationRepository;
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
 * Orchestrates `RecordVitalSigns` (IC §5; ADR-132, ADR-133, ADR-135, ADR-138).
 *
 * Flow (mirrors `CreateClinicalNoteHandler` — vitals origination is gated
 * exactly as clinical-note origination, ADR-138 D10/D11):
 *   1. coarse `clinical.vitals.record` — already enforced by the bus
 *      (`VitalSignsObservationPolicy::record`).
 *   2. `PatientApi::getPatientSummary` — `NotFound` for unknown / cross-tenant;
 *      `AuthorizationException` if the caller lacks `patient.patients.read`.
 *      Patient-state gate: `ACTIVE` only, `isProvisional` permitted (ADR-119);
 *      MERGED / DECEASED / INACTIVE → `PatientNotEligibleForClinicalRecord` (409).
 *   3. `EncounterApi::getEncounterContext` — the E.13 port (`NotFound` for
 *      absent / cross-tenant / out-of-scope encounter).
 *   4. ADR-133 per-encounter-facility refinement — the actor must hold
 *      `clinical.vitals.record` at THE ENCOUNTER's facility; otherwise `NotFound`.
 *   5. patient / encounter consistency — `patientId` must be the encounter's
 *      patient (`VitalSignsContextMismatch`, 409).
 *   6. encounter-state gate — origination requires status ∈ {ARRIVED, ACTIVE,
 *      COMPLETED} (`EncounterNotEligibleForClinicalRecord`, 409).
 *   7. `facility_id` copied from the encounter context; record the observation;
 *      `occurred_at` = caller value or `now()`, `recorded_at` = `now()`.
 *   8. versioned save + `VitalSignsRecorded` to the outbox (one txn). The
 *      `clinical.vitals.recorded` audit row (with the backdate `reason`, when
 *      present — never a measurement value) is written by the bus `WriteAuditRow`
 *      step.
 *
 * `VitalSignsObservationRepository` is injected both because the handler needs
 * it and because `AuthorizeCommand` (IC §5.1) reflects it to resolve the
 * `VitalSignsObservation` aggregate for the class-level `record` check. NO
 * direct access to `pat_*` / `enc_*` tables or aggregates (AGENTS §8; IC §2.1).
 */
final class RecordVitalSignsHandler
{
    public function __construct(
        private readonly VitalSignsObservationRepository $observations,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordVitalSigns $command): RecordVitalSignsResult
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->vitalSignsObservationId);

        if ($encounter->patientId !== $command->patientId) {
            throw VitalSignsContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $this->assertEncounterEligible($encounter);

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;

        $observation = VitalSignsObservation::record(
            id: $command->vitalSignsObservationId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            authorUserId: $command->actorId,
            authoredContext: $command->authoredContext,
            measurements: $command->measurements,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $this->observations->save($observation); // versioned INSERT + VitalSignsRecorded -> outbox (one txn)

        return new RecordVitalSignsResult((string) $observation->id);
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
     * ADR-133 refinement — the authenticated recorder must hold
     * `clinical.vitals.record` at the encounter's own facility, not merely at
     * their selected context. An out-of-scope encounter is indistinguishable
     * from an absent one (`NotFound`), never an authorization disclosure (the
     * E.12 R-1 pattern; the `Auth`-resolved actor cannot be spoofed).
     */
    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $observationId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordVitalSigns dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'clinical.vitals.record',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(VitalSignsObservation::class, $observationId);
        }
    }

    /**
     * Encounter-state gate (ADR-135 / ADR-138 D10) — ONLY origination is gated.
     * A vitals observation is recorded only when the encounter is `ARRIVED`,
     * `ACTIVE` or `COMPLETED`.
     */
    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForClinicalRecord::status($encounter->encounterId, $encounter->status);
        }
    }
}
