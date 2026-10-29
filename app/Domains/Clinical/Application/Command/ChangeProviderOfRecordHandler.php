<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\CareTeamMembershipRepository;
use App\Domains\Clinical\Domain\Exception\ProviderOfRecordConflict;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Orchestrates `ChangeProviderOfRecord` (IC §5; ADR-142 D-C / D-G / D-J / D-N /
 * D-T).
 *
 * Flow (post-origination — NO `EncounterApi` / `PatientApi` call):
 *   1. resolve the authenticated actor (from `Auth`, never a command field).
 *   2. load the encounter's ACTIVE memberships (RLS-scoped). An encounter with
 *      no in-tenant memberships -> `NotFound` (non-disclosing — there is
 *      nothing to change and, for an out-of-scope actor, nothing visible;
 *      ADR-142 D-N).
 *   3. ADR-142 D-N per-encounter-facility refinement — all of an encounter's
 *      memberships share one originating `facility_id`; the actor must hold
 *      `clinical.care_team.record` there, else `NotFound` (the E.12 R-1
 *      pattern). Applied BEFORE any 409 so scope never leaks.
 *   4. the incoming `newProviderStaffUserId` MUST hold an ACTIVE membership on
 *      the encounter (`ProviderOfRecordConflict::notAnActiveMember`, 409 —
 *      ADR-142 D-G).
 *   5. reject a no-op (the incoming is already the active provider-of-record —
 *      `ProviderOfRecordConflict::alreadyProviderOfRecord`, 409; the
 *      `VerifyAllergy` "not idempotent" discipline).
 *   6. atomic hand-off (ADR-142 D-J): clear the outgoing provider-of-record's
 *      flag (if any — first assignment has none), designate the incoming (which
 *      records the single `ProviderOfRecordChanged` event carrying the previous
 *      / new ids). Both membership rows stay ACTIVE.
 *   7. `CareTeamMembershipRepository::changeProviderOfRecord()` persists both in
 *      the bus transaction (outgoing first — vacates the partial unique index).
 *      A concurrent-race unique violation on
 *      `clin_one_provider_of_record_per_encounter` -> 409
 *      (`ProviderOfRecordConflict::concurrentChange`). The
 *      `clinical.care_team.provider_of_record_changed` audit row (`metadata =
 *      []`, `reason = null` — ADR-142 D-S) is written by the bus.
 *
 * `CareTeamMembershipRepository` is injected both because the handler needs it
 * and because `AuthorizeCommand` (IC §5.1) reflects it to resolve the
 * `CareTeamMembership` aggregate for the class-level `changeProviderOfRecord`
 * policy check.
 */
final class ChangeProviderOfRecordHandler
{
    public function __construct(
        private readonly CareTeamMembershipRepository $memberships,
        private readonly ScopeChecker $scope,
    ) {}

    public function __invoke(ChangeProviderOfRecord $command): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('ChangeProviderOfRecord dispatched without an authenticated user (IC §5).');
        }

        $active = $this->memberships->activeForEncounter($command->encounterId);

        if ($active === []) {
            throw NotFound::aggregate(CareTeamMembership::class, $command->encounterId);
        }

        $facilityId = (string) $active[0]->facility_id;

        if (! in_array(
            'clinical.care_team.record',
            $this->scope->effectivePermissions($user, $facilityId),
            true,
        )) {
            throw NotFound::aggregate(CareTeamMembership::class, $command->encounterId);
        }

        $incoming = null;
        $outgoing = null;

        foreach ($active as $membership) {
            if ((string) $membership->staff_user_id === $command->newProviderStaffUserId && $incoming === null) {
                $incoming = $membership;
            }

            if ($membership->is_provider_of_record && $outgoing === null) {
                $outgoing = $membership;
            }
        }

        if ($incoming === null) {
            throw ProviderOfRecordConflict::notAnActiveMember($command->encounterId, $command->newProviderStaffUserId);
        }

        if ($incoming->is_provider_of_record) {
            throw ProviderOfRecordConflict::alreadyProviderOfRecord($command->encounterId, $command->newProviderStaffUserId);
        }

        $now = CarbonImmutable::now();

        if ($outgoing !== null) {
            $outgoing->relinquishProviderOfRecord();
        }

        $incoming->assignAsProviderOfRecord(
            previousProviderStaffUserId: $outgoing !== null ? (string) $outgoing->staff_user_id : null,
            previousProviderMembershipId: $outgoing !== null ? (string) $outgoing->id : null,
            changedAt: $now,
        );

        try {
            $this->memberships->changeProviderOfRecord($outgoing, $incoming);
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), 'clin_one_provider_of_record_per_encounter')) {
                throw ProviderOfRecordConflict::concurrentChange($command->encounterId);
            }

            throw $e;
        }
    }
}
