<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\ChangeProviderOfRecord;
use App\Domains\Clinical\Domain\CareTeamMembershipRepository;
use App\Domains\Clinical\Domain\Exception\DuplicateActiveCareTeamMembership;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use App\Shared\Bus\CommandBus;
use App\Shared\Concurrency\ConcurrencyConflict;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.6) MANDATORY concurrency gate (IC §9.1; AGENTS §40.1; ADR-142 D-U
 | / D-T). The database is authoritative under races — both partial unique
 | indexes yield exactly one winner; the handler pre-check is friendly only.
 | The race is simulated the CreateEncounter way: commit the first writer, then
 | dispatch the second and assert it loses.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/care_team.php';

test('two concurrent AddCareTeamMember for the same (encounter, staff, role) — the DB index yields exactly one winner', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffUserId = makeStaffUser((string) $tenant->id);

    // writer 1 commits
    $first = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['teamRole' => TeamRole::NURSE]);

    // writer 2 races in with an already-committed row present — the partial unique
    // index `clin_one_active_membership_per_encounter_staff_role` rejects it.
    // (a raw insert bypasses the handler pre-check to prove the DB is authoritative)
    expect(fn () => Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'facility_id' => (string) $facility->id,
        'encounter_id' => $encounterId,
        'patient_id' => $patientId,
        'staff_user_id' => $staffUserId,
        'team_role' => TeamRole::NURSE,
        'is_provider_of_record' => false,
        'active_from' => now(),
        'active_to' => null,
        'added_by_user_id' => (string) $user->id,
        'version' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')
        ->where('encounter_id', $encounterId)->whereNull('active_to')->count()))->toBe(1);

    // and through the handler the loser gets a clean 409
    expect(fn () => clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffUserId, (string) $user->id, ['teamRole' => TeamRole::NURSE]))
        ->toThrow(DuplicateActiveCareTeamMembership::class);
});

test('two concurrent provider-of-record assignments for one encounter — the DB index yields exactly one active PoR', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $staffA = makeStaffUser((string) $tenant->id);
    $staffB = makeStaffUser((string) $tenant->id);
    $mA = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffA, (string) $user->id);
    $mB = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $staffB, (string) $user->id);

    // writer 1: A becomes PoR (commits)
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new ChangeProviderOfRecord($encounterId, (string) $user->id, $staffA)
    ));

    // writer 2 races in with a raw "make B the PoR" against the already-committed A —
    // the partial unique index `clin_one_provider_of_record_per_encounter` rejects it.
    expect(fn () => Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')
        ->where('id', $mB)->update(['is_provider_of_record' => true])))
        ->toThrow(UniqueConstraintViolationException::class);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')
        ->where('encounter_id', $encounterId)->where('is_provider_of_record', true)->whereNull('active_to')->count()))->toBe(1);
    expect((bool) Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $mA)->value('is_provider_of_record')))->toBeTrue();
});

test('optimistic concurrency: a stale in-memory end() save -> ConcurrencyConflict (409); no partial state', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $membershipId = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, makeStaffUser((string) $tenant->id), (string) $user->id);

    try {
        TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($membershipId, $user): void {
            $membership = CareTeamMembership::query()->findOrFail($membershipId);
            CareTeamMembership::query()->whereKey($membershipId)->update(['version' => 9]);
            $membership->end((string) $user->id, null, CarbonImmutable::now());
            app(CareTeamMembershipRepository::class)->save($membership);
        });
        test()->fail('expected ConcurrencyConflict');
    } catch (ConcurrencyConflict) {
        // expected — UPDATE ... WHERE version = 0 affected 0 rows
    }

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_care_team_memberships')->where('id', $membershipId)->value('active_to')))->toBeNull();
});
