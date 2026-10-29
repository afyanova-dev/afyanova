<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\CareTeamMembershipRepository;
use App\Domains\Clinical\Domain\Exception\CareTeamMemberNotStaff;
use App\Domains\Clinical\Domain\Exception\DuplicateActiveCareTeamMembership;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForCareTeam;
use App\Domains\Clinical\Domain\Exception\PatientNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\Exception\ProviderOfRecordConflict;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Patient\Contracts\Dto\PatientSummary;
use App\Domains\Patient\Contracts\PatientApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `AddCareTeamMember` (IC §5; ADR-132, ADR-135, ADR-142 D-E …
 * D-K / D-N / D-P / D-T).
 *
 * Flow (the `AddProblemHandler` origination shape — a care-team assignment is
 * ORIGINATED against an encounter, but is NOT a clinical record, so it has its
 * own encounter-state gate):
 *   1. coarse `clinical.care_team.record` — already enforced by the bus
 *      (`CareTeamMembershipPolicy::record`).
 *   2. `EncounterApi::getEncounterContext` — `NotFound` for absent /
 *      cross-tenant / out-of-facility-scope encounter. Yields `facility_id`,
 *      `patient_id` and `status`.
 *   3. ADR-142 D-N per-encounter-facility refinement — the authenticated actor
 *      must hold `clinical.care_team.record` at THE ENCOUNTER's facility;
 *      otherwise `NotFound` (the E.12 R-1 pattern — an out-of-scope encounter
 *      is indistinguishable from an absent one; the `Auth`-resolved actor
 *      cannot be spoofed). Applied BEFORE any 409 so scope never leaks.
 *   4. encounter-state gate (ADR-142 D-K — NOT ADR-135's clinical-record set):
 *      status in {PLANNED, ARRIVED, ACTIVE, COMPLETED}
 *      (`EncounterNotEligibleForCareTeam`, 409). PLANNED is permitted.
 *   5. patient-state gate (ADR-142 D-L) — `PatientApi::getPatientSummary`
 *      against the encounter's patient; `ACTIVE` only, `isProvisional`
 *      permitted (ADR-119); MERGED / DECEASED / INACTIVE ->
 *      `PatientNotEligibleForClinicalRecord` (409).
 *   6. staff validation (ADR-142 D-E) — `IdentityApi::isStaff($staffUserId)`;
 *      `false` -> `CareTeamMemberNotStaff` (409).
 *   7. duplicate pre-check — a friendly 409 if the staff user already holds an
 *      active membership with the same `team_role` on the encounter
 *      (`DuplicateActiveCareTeamMembership`); the DB partial unique index
 *      `clin_one_active_membership_per_encounter_staff_role` is the
 *      authoritative guard (ADR-142 D-D / D-T).
 *   8. provider-of-record pre-check — a friendly 409 if `isProviderOfRecord` is
 *      true and the encounter already has an active provider-of-record
 *      (`ProviderOfRecordConflict::alreadyAssigned`); the DB partial unique
 *      index `clin_one_provider_of_record_per_encounter` is authoritative
 *      (ADR-142 D-G / D-T).
 *   9. `facility_id` / `patient_id` copied from the encounter context;
 *      `active_from` = server `now()`; add the ACTIVE membership.
 *  10. versioned INSERT + `CareTeamMemberAdded` (+ `ProviderOfRecordChanged`
 *      when it sets the first provider-of-record) to the outbox (one txn). A
 *      concurrent-race unique violation is translated to the matching 409. The
 *      `clinical.care_team.member_added` audit row (`metadata = []`, `reason =
 *      null` — ADR-142 D-S) is written by the bus.
 *
 * NO direct access to `pat_*` / `enc_*` / `idn_*` tables or aggregates
 * (AGENTS §8; IC §2.1) — only the published `PatientApi` / `EncounterApi` /
 * `IdentityApi` contracts.
 */
final class AddCareTeamMemberHandler
{
    public function __construct(
        private readonly CareTeamMembershipRepository $memberships,
        private readonly PatientApi $patients,
        private readonly EncounterApi $encounters,
        private readonly IdentityApi $identity,
        private readonly ScopeChecker $scope,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AddCareTeamMember $command): AddCareTeamMemberResult
    {
        $encounter = $this->encounters->getEncounterContext($command->encounterId);

        $this->assertActorScopedToEncounterFacility($encounter, $command->careTeamMembershipId);

        $this->assertEncounterEligible($encounter);

        $this->assertPatientEligible($this->patients->getPatientSummary($encounter->patientId));

        if (! $this->identity->isStaff($command->staffUserId)) {
            throw CareTeamMemberNotStaff::forUser($command->staffUserId);
        }

        $active = $this->memberships->activeForEncounter($command->encounterId);

        foreach ($active as $membership) {
            if ((string) $membership->staff_user_id === $command->staffUserId
                && (string) $membership->team_role === $command->teamRole) {
                throw DuplicateActiveCareTeamMembership::forEncounterStaffRole(
                    $command->encounterId,
                    $command->staffUserId,
                    $command->teamRole,
                );
            }
        }

        if ($command->isProviderOfRecord) {
            foreach ($active as $membership) {
                if ($membership->is_provider_of_record) {
                    throw ProviderOfRecordConflict::alreadyAssigned($command->encounterId);
                }
            }
        }

        $membership = CareTeamMembership::add(
            id: $command->careTeamMembershipId,
            tenantId: $this->context->tenantId(),
            facilityId: $encounter->facilityId,
            encounterId: $command->encounterId,
            patientId: $encounter->patientId,
            staffUserId: $command->staffUserId,
            teamRole: $command->teamRole,
            isProviderOfRecord: $command->isProviderOfRecord,
            addedByUserId: $command->actorId,
            activeFrom: CarbonImmutable::now(),
        );

        try {
            $this->memberships->save($membership); // versioned INSERT + events -> outbox (one txn)
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'clin_one_provider_of_record_per_encounter')) {
                throw ProviderOfRecordConflict::alreadyAssigned($command->encounterId);
            }

            if (str_contains($e->getMessage(), 'clin_one_active_membership_per_encounter_staff_role')) {
                throw DuplicateActiveCareTeamMembership::forEncounterStaffRole(
                    $command->encounterId,
                    $command->staffUserId,
                    $command->teamRole,
                );
            }

            throw $e;
        }

        return new AddCareTeamMemberResult((string) $membership->id);
    }

    /**
     * ADR-142 D-N refinement — the authenticated user must hold
     * `clinical.care_team.record` at the encounter's OWN facility, not merely
     * at their selected context. An out-of-scope encounter is indistinguishable
     * from an absent one (`NotFound`), never an authorization disclosure.
     */
    private function assertActorScopedToEncounterFacility(EncounterContext $encounter, string $membershipId): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('AddCareTeamMember dispatched without an authenticated user (IC §5).');
        }

        if (! in_array(
            'clinical.care_team.record',
            $this->scope->effectivePermissions($user, $encounter->facilityId),
            true,
        )) {
            throw NotFound::aggregate(CareTeamMembership::class, $membershipId);
        }
    }

    /**
     * Encounter-state gate (ADR-142 D-K) — a care-team member is added only
     * when the encounter is `PLANNED`, `ARRIVED`, `ACTIVE` or `COMPLETED`.
     * `PLANNED` is the deliberate divergence from ADR-135 (a `CareTeamMembership`
     * is not a clinical record); ADR-135 is unchanged.
     */
    private function assertEncounterEligible(EncounterContext $encounter): void
    {
        if (! in_array($encounter->status, ['PLANNED', 'ARRIVED', 'ACTIVE', 'COMPLETED'], true)) {
            throw EncounterNotEligibleForCareTeam::status($encounter->encounterId, $encounter->status);
        }
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
}
