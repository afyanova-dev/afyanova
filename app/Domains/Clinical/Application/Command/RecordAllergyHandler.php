<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\AllergyRepository;
use App\Domains\Clinical\Domain\Exception\AllergyContextMismatch;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Model\Allergy;
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
 * Orchestrates `RecordAllergy` (IC §5; ADR-132, ADR-135, ADR-141 D-B / D-K /
 * D-P).
 *
 * Flow (mirrors `AddProblemHandler` — an allergy is ORIGINATED against an
 * encounter and gated exactly as any clinical-record origination):
 *   1. coarse `clinical.allergies.record` — already enforced by the bus
 *      (`AllergyPolicy::record`).
 *   2. `PatientApi::getPatientSummary` — `NotFound` for unknown / cross-tenant;
 *      `AuthorizationException` if the caller lacks `patient.patients.read`.
 *      Patient-state gate: `ACTIVE` only, `isProvisional` permitted (ADR-119);
 *      MERGED / DECEASED / INACTIVE -> `PatientNotEligibleForClinicalRecord` (409).
 *   3. `EncounterApi::getEncounterContext` — `NotFound` for absent / cross-tenant
 *      / out-of-scope encounter.
 *   4. ADR-141 per-encounter-facility refinement — the authenticated actor must
 *      hold `clinical.allergies.record` at THE ENCOUNTER's facility; otherwise
 *      `NotFound` (the E.12 R-1 pattern; the `Auth`-resolved actor cannot be
 *      spoofed). WRITES are originating-facility-refined even though READS are
 *      tenant-wide (ADR-141 D-M).
 *   5. patient / encounter consistency — `patientId` must be the encounter's
 *      patient (`AllergyContextMismatch::patientEncounter`, 409).
 *   6. encounter-state gate — origination requires status in {ARRIVED, ACTIVE,
 *      COMPLETED} (`EncounterNotEligibleForClinicalRecord`, 409).
 *   7. `facility_id` copied from the encounter context; record the ACTIVE
 *      allergy (`SUSPECTED` or `CONFIRMED`); `occurred_at` = caller value or
 *      `now()`, `recorded_at` = `now()`.
 *   8. versioned save + `AllergyRecorded` to the outbox (one txn). The
 *      `clinical.allergies.recorded` audit row (with the backdate `reason`, when
 *      present — never the substance / reaction / severity / onset date) is
 *      written by the bus `WriteAuditRow` step.
 *
 * `AllergyRepository` is injected both because the handler needs it and because
 * `AuthorizeCommand` (IC §5.1) reflects it to resolve the `Allergy` aggregate
 * for the class-level `record` check. NO direct access to `pat_*` / `enc_*`
 * tables or aggregates (AGENTS §8; IC §2.1). NO terminology / medication API
 * (ADR-141 D-P).
 */
final class RecordAllergyHandler
{
    public function __construct(
        private readonly AllergyRepository $allergies,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordAllergy $command): RecordAllergyResult
    {
        $this->assertPatientEligible($this->patients->getPatientSummary($command->patientId));

        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->allergyId);

        if ($encounter->patientId !== $command->patientId) {
            throw AllergyContextMismatch::patientEncounter(
                $command->patientId,
                $command->encounterId,
                $encounter->patientId,
            );
        }

        $this->assertEncounterEligible($encounter);

        $now = CarbonImmutable::now();
        $occurredAt = $command->occurredAt !== null ? CarbonImmutable::parse($command->occurredAt) : $now;
        $onsetDate = $command->onsetDate !== null ? CarbonImmutable::parse($command->onsetDate) : null;

        $allergy = Allergy::record(
            id: $command->allergyId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            patientId: $command->patientId,
            encounterId: $command->encounterId,
            authorUserId: $command->actorId,
            authoredContext: $command->authoredContext,
            category: $command->category,
            substance: $command->substance,
            reactionDescription: $command->reactionDescription,
            severity: $command->severity,
            verificationStatus: $command->verificationStatus,
            onsetDate: $onsetDate,
            occurredAt: $occurredAt,
            recordedAt: $now,
        );

        $this->allergies->save($allergy); // versioned INSERT + AllergyRecorded -> outbox (one txn)

        return new RecordAllergyResult((string) $allergy->id);
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
     * ADR-141 D-K / D-M refinement — WRITE authorization stays
     * originating-facility-refined: the authenticated clinician / nurse must
     * hold `clinical.allergies.record` at the encounter's own facility, not
     * merely at their selected context. An out-of-scope encounter is
     * indistinguishable from an absent one (`NotFound`), never an authorization
     * disclosure (the E.12 R-1 pattern; the `Auth`-resolved actor cannot be
     * spoofed).
     */
    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $allergyId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('RecordAllergy dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'clinical.allergies.record',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(Allergy::class, $allergyId);
        }
    }

    /**
     * Encounter-state gate (ADR-135 / ADR-141 D-B) — ONLY origination is gated.
     * An allergy is recorded only when the encounter is `ARRIVED`, `ACTIVE` or
     * `COMPLETED`.
     */
    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForClinicalRecord::status($encounter->encounterId, $encounter->status);
        }
    }
}
