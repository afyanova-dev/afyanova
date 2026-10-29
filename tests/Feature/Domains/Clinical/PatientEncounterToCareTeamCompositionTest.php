<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\ChangeProviderOfRecord;
use App\Domains\Clinical\Application\Command\EndCareTeamMembership;
use App\Domains\Clinical\Application\Query\GetCareTeamMembership;
use App\Domains\Clinical\Application\Query\GetEncounterCareTeam;
use App\Domains\Clinical\Contracts\Dto\CareTeamMembershipSummary;
use App\Domains\Clinical\Contracts\Event\ProviderOfRecordChanged;
use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.6) cross-domain gate — the canonical Patient -> Encounter ->
 | Care Team composition (blueprint.md; IC §20.2; AGENTS §43). REAL
 | CommandBus / QueryBus / PatientApi / EncounterApi / IdentityApi — never
 | mocked (ADR-135). Proves the ADR-142 gates on origination and that End /
 | ChangeProviderOfRecord / reads are independent of the encounter's later
 | state.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/care_team.php';

test('RegisterPatient -> Encounter -> AddCareTeamMember x2 -> ChangeProviderOfRecord -> Complete encounter -> End (ungated) -> Get', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careTeamFixture();

    // 1. Patient
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    // 2. Encounter -> ACTIVE
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    // 3. Two staff members; the attending is the first provider-of-record
    $attending = makeStaffUser((string) $tenant->id);
    $consultant = makeStaffUser((string) $tenant->id);

    $attendingMembership = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $attending, (string) $user->id, [
        'teamRole' => TeamRole::ATTENDING, 'isProviderOfRecord' => true,
    ]);
    $consultantMembership = clinicalAddCareTeamMember((string) $tenant->id, (string) $facility->id, $encounterId, $consultant, (string) $user->id, [
        'teamRole' => TeamRole::CONSULTANT,
    ]);

    // 4. Hand the provider-of-record to the consultant
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new ChangeProviderOfRecord($encounterId, (string) $user->id, $consultant)
    ));

    // 5. Drive the encounter to COMPLETED
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    // 6. End the attending's membership on the COMPLETED encounter — post-origination, ungated
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new EndCareTeamMembership($attendingMembership, (string) $user->id, 'left the service')
    ));

    // 7. Read back the current care team
    $team = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetEncounterCareTeam($encounterId)));

    expect($team)->toHaveCount(1)
        ->and($team[0])->toBeInstanceOf(CareTeamMembershipSummary::class)
        ->and($team[0]->staffUserId)->toBe($consultant)
        ->and($team[0]->isProviderOfRecord)->toBeTrue()
        ->and($team[0]->teamRole)->toBe('CONSULTANT');

    // history is reachable via includeEnded
    $full = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetEncounterCareTeam($encounterId, true)));
    expect($full)->toHaveCount(2);

    $endedSummary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetCareTeamMembership($attendingMembership)));
    expect($endedSummary->activeTo)->not->toBeNull()
        ->and($endedSummary->endReason)->toBe('left the service')  // returned to an authorized reader
        ->and($endedSummary->isProviderOfRecord)->toBeFalse();

    // provider-of-record assignment history is losslessly reconstructable from the event stream
    $porEvents = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ProviderOfRecordChanged::class)
        ->orderBy('id')->pluck('payload')->all());
    $decoded = array_map(fn (string $p): array => json_decode($p, true, 512, JSON_THROW_ON_ERROR), $porEvents);

    expect($decoded)->toHaveCount(2)
        ->and($decoded[0]['previousProviderStaffUserId'])->toBeNull()            // first assignment at add
        ->and($decoded[0]['newProviderStaffUserId'])->toBe($attending)
        ->and($decoded[1]['previousProviderStaffUserId'])->toBe($attending)      // hand-off
        ->and($decoded[1]['newProviderStaffUserId'])->toBe($consultant);

    // audit: exactly the three C.6 actions, metadata = [], reason = null
    $actions = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->whereIn('action', ['clinical.care_team.member_added', 'clinical.care_team.member_removed', 'clinical.care_team.provider_of_record_changed'])
        ->get());
    expect($actions)->toHaveCount(4); // 2 add + 1 change + 1 remove
    foreach ($actions as $a) {
        expect($a->reason)->toBeNull()->and(json_decode((string) $a->metadata, true))->toBe([]);
    }
});
