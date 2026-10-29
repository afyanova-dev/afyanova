<?php

declare(strict_types=1);

use App\Domains\Clinical\Contracts\Event\CareTeamMemberAdded;
use App\Domains\Clinical\Contracts\Event\CareTeamMemberRemoved;
use App\Domains\Clinical\Contracts\Event\ProviderOfRecordChanged;
use App\Domains\Clinical\Domain\Exception\IllegalCareTeamMembershipTransition;
use App\Domains\Clinical\Domain\Model\CareTeamMembership;
use App\Domains\Clinical\Domain\ValueObject\TeamRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.6) unit gate — the CareTeamMembership aggregate root
 | (12-clinical-domain.md §4.1, §162; ADR-142 D-C … D-J / D-R). Bounded-life
 | membership, immutable role, terminal ENDED, is_provider_of_record flag,
 | NO history table, NO provider-of-record rationale.
 */

function makeMembership(array $overrides = []): CareTeamMembership
{
    return CareTeamMembership::add(
        id: $overrides['id'] ?? (string) Str::uuid7(),
        tenantId: $overrides['tenantId'] ?? (string) Str::uuid7(),
        facilityId: $overrides['facilityId'] ?? (string) Str::uuid7(),
        encounterId: $overrides['encounterId'] ?? (string) Str::uuid7(),
        patientId: $overrides['patientId'] ?? (string) Str::uuid7(),
        staffUserId: $overrides['staffUserId'] ?? (string) Str::uuid7(),
        teamRole: $overrides['teamRole'] ?? TeamRole::ATTENDING,
        isProviderOfRecord: $overrides['isProviderOfRecord'] ?? false,
        addedByUserId: $overrides['addedByUserId'] ?? (string) Str::uuid7(),
        activeFrom: $overrides['activeFrom'] ?? CarbonImmutable::now(),
    );
}

test('add() produces a version-0 ACTIVE membership and records CareTeamMemberAdded', function () {
    $membership = makeMembership(['teamRole' => TeamRole::NURSE]);

    expect((int) $membership->version)->toBe(0)
        ->and($membership->isActive())->toBeTrue()
        ->and($membership->active_to)->toBeNull()
        ->and((string) $membership->team_role)->toBe('NURSE')
        ->and((bool) $membership->is_provider_of_record)->toBeFalse()
        ->and($membership->isProviderOfRecord())->toBeFalse();

    $events = $membership->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CareTeamMemberAdded::class)
        ->and($events[0]->teamRole)->toBe('NURSE')
        ->and($events[0]->isProviderOfRecord)->toBeFalse()
        ->and($events[0]->careTeamMembershipId)->toBe((string) $membership->id);
});

test('add() with isProviderOfRecord = true records CareTeamMemberAdded + ProviderOfRecordChanged (previous = null)', function () {
    $membership = makeMembership(['isProviderOfRecord' => true]);

    expect($membership->isProviderOfRecord())->toBeTrue();

    $events = $membership->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(CareTeamMemberAdded::class)
        ->and($events[0]->isProviderOfRecord)->toBeTrue()
        ->and($events[1])->toBeInstanceOf(ProviderOfRecordChanged::class)
        ->and($events[1]->previousProviderStaffUserId)->toBeNull()
        ->and($events[1]->previousProviderMembershipId)->toBeNull()
        ->and($events[1]->newProviderStaffUserId)->toBe((string) $membership->staff_user_id)
        ->and($events[1]->newProviderMembershipId)->toBe((string) $membership->id);
});

test('add() rejects an unknown team_role (ADR-142 D-F)', function () {
    expect(fn () => makeMembership(['teamRole' => 'SURGEON']))->toThrow(InvalidArgumentException::class);
});

test('end() transitions ACTIVE -> ENDED, sets active_to / removed_by / end_reason, records CareTeamMemberRemoved', function () {
    $membership = makeMembership();
    $membership->releaseEvents();

    $removedBy = (string) Str::uuid7();
    $membership->end($removedBy, '  handover to ward team  ', CarbonImmutable::now());

    expect($membership->isActive())->toBeFalse()
        ->and($membership->active_to)->not->toBeNull()
        ->and((string) $membership->removed_by_user_id)->toBe($removedBy)
        ->and($membership->end_reason)->toBe('handover to ward team'); // trimmed, ROW only

    $events = $membership->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CareTeamMemberRemoved::class)
        ->and($events[0]->wasProviderOfRecord)->toBeFalse();

    // ADR-142 D-H — end_reason is NEVER an event field
    expect((array) $events[0])->not->toHaveKey('endReason')
        ->and((array) $events[0])->not->toHaveKey('reason');
});

test('end() of a blank / null reason stores null end_reason', function () {
    $a = makeMembership();
    $a->releaseEvents();
    $a->end((string) Str::uuid7(), '   ', CarbonImmutable::now());
    expect($a->end_reason)->toBeNull();

    $b = makeMembership();
    $b->releaseEvents();
    $b->end((string) Str::uuid7(), null, CarbonImmutable::now());
    expect($b->end_reason)->toBeNull();
});

test('end() of the provider-of-record clears the flag and records ProviderOfRecordChanged (new = null)', function () {
    $membership = makeMembership(['isProviderOfRecord' => true]);
    $membership->releaseEvents();

    $membership->end((string) Str::uuid7(), null, CarbonImmutable::now());

    expect((bool) $membership->is_provider_of_record)->toBeFalse();

    $events = $membership->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(CareTeamMemberRemoved::class)
        ->and($events[0]->wasProviderOfRecord)->toBeTrue()
        ->and($events[1])->toBeInstanceOf(ProviderOfRecordChanged::class)
        ->and($events[1]->previousProviderStaffUserId)->toBe((string) $membership->staff_user_id)
        ->and($events[1]->newProviderStaffUserId)->toBeNull();
});

test('end() is terminal — a second end() is rejected (no reactivation)', function () {
    $membership = makeMembership();
    $membership->releaseEvents();
    $membership->end((string) Str::uuid7(), null, CarbonImmutable::now());
    $membership->releaseEvents();

    expect(fn () => $membership->end((string) Str::uuid7(), null, CarbonImmutable::now()))
        ->toThrow(IllegalCareTeamMembershipTransition::class);
});

test('assignAsProviderOfRecord() sets the flag and records the single ProviderOfRecordChanged', function () {
    $incoming = makeMembership();
    $incoming->releaseEvents();

    $prevStaff = (string) Str::uuid7();
    $prevMembership = (string) Str::uuid7();
    $incoming->assignAsProviderOfRecord($prevStaff, $prevMembership, CarbonImmutable::now());

    expect((bool) $incoming->is_provider_of_record)->toBeTrue();

    $events = $incoming->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProviderOfRecordChanged::class)
        ->and($events[0]->previousProviderStaffUserId)->toBe($prevStaff)
        ->and($events[0]->previousProviderMembershipId)->toBe($prevMembership)
        ->and($events[0]->newProviderStaffUserId)->toBe((string) $incoming->staff_user_id)
        ->and($events[0]->newProviderMembershipId)->toBe((string) $incoming->id);
});

test('assignAsProviderOfRecord() rejects an ENDED membership (provider-of-record must be active)', function () {
    $membership = makeMembership();
    $membership->releaseEvents();
    $membership->end((string) Str::uuid7(), null, CarbonImmutable::now());
    $membership->releaseEvents();

    expect(fn () => $membership->assignAsProviderOfRecord(null, null, CarbonImmutable::now()))
        ->toThrow(IllegalCareTeamMembershipTransition::class);
});

test('relinquishProviderOfRecord() clears the flag WITHOUT ending the membership and records NO event', function () {
    $outgoing = makeMembership(['isProviderOfRecord' => true]);
    $outgoing->releaseEvents();

    $outgoing->relinquishProviderOfRecord();

    expect((bool) $outgoing->is_provider_of_record)->toBeFalse()
        ->and($outgoing->isActive())->toBeTrue()
        ->and($outgoing->releaseEvents())->toBe([]);
});

test('every care-team event is PHI-free — identifiers + coded team_role + booleans only', function () {
    $membership = makeMembership(['isProviderOfRecord' => true]);
    $addEvents = $membership->releaseEvents();
    $membership->end((string) Str::uuid7(), 'private clinical rationale text', CarbonImmutable::now());
    $endEvents = $membership->releaseEvents();

    foreach ([...$addEvents, ...$endEvents] as $event) {
        foreach ((array) $event as $key => $value) {
            // no free-text reason / name / narrative fields
            expect($key)->not->toContain('reason')
                ->and($key)->not->toContain('name')
                ->and($key)->not->toContain('Reason')
                ->and($key)->not->toContain('note');

            if (is_string($value)) {
                expect($value)->not->toContain('private clinical rationale text');
            }
        }
    }
});
