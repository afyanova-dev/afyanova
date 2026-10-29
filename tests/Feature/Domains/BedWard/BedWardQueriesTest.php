<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\AssignBed;
use App\Domains\BedWard\Application\Command\ReleaseBed;
use App\Domains\BedWard\Application\Query\GetAvailableBeds;
use App\Domains\BedWard\Application\Query\GetBed;
use App\Domains\BedWard\Application\Query\GetBedOccupancyHistory;
use App\Domains\BedWard\Application\Query\GetEncounterBed;
use App\Domains\BedWard\Application\Query\GetFacilityOccupancy;
use App\Domains\BedWard\Application\Query\GetWard;
use App\Domains\BedWard\Application\Query\GetWardCensus;
use App\Domains\BedWard\Contracts\Dto\BedSummary;
use App\Domains\BedWard\Contracts\Dto\FacilityOccupancySummary;
use App\Domains\BedWard\Contracts\Dto\WardSummary;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\ValueObject\BedEndReason;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('GetWard and GetBed return summaries', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id, [
        'code' => 'W-QRY',
        'name' => 'Query Ward',
        'ward_type' => 'GENERAL',
        'capacity' => 15,
    ]);

    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'label' => 'B-QRY-1',
        'bed_class' => 'STANDARD',
        'features' => ['OXYGEN'],
    ]);

    // Query ward
    /** @var WardSummary $wardSummary */
    $wardSummary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetWard((string) $ward->id)));

    expect($wardSummary->wardId)->toBe((string) $ward->id)
        ->and($wardSummary->code)->toBe('W-QRY')
        ->and($wardSummary->name)->toBe('Query Ward')
        ->and($wardSummary->capacity)->toBe(15);

    // Query bed
    /** @var BedSummary $bedSummary */
    $bedSummary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetBed((string) $bed->id)));

    expect($bedSummary->bedId)->toBe((string) $bed->id)
        ->and($bedSummary->label)->toBe('B-QRY-1')
        ->and($bedSummary->features)->toBe(['OXYGEN'])
        ->and($bedSummary->status)->toBe(Bed::STATUS_AVAILABLE);
});

test('GetWardCensus returns status counts', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'B-AV1', 'status' => Bed::STATUS_AVAILABLE]);
    createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'B-AV2', 'status' => Bed::STATUS_AVAILABLE]);
    createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'B-OCC', 'status' => Bed::STATUS_OCCUPIED]);
    createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'B-CLN', 'status' => Bed::STATUS_CLEANING]);

    $census = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetWardCensus((string) $ward->id)));

    expect($census)->toBeArray()
        ->and($census['AVAILABLE'])->toBe(2)
        ->and($census['OCCUPIED'])->toBe(1)
        ->and($census['CLEANING'])->toBe(1)
        ->and($census['TOTAL'])->toBe(4);
});

test('GetAvailableBeds filters by features and bed_class', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id, ['ward_type' => 'ICU']);
    createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'label' => 'ICU-1',
        'bed_class' => 'ICU_ELECTRIC',
        'features' => ['VENTILATOR', 'MONITOR'],
        'status' => Bed::STATUS_AVAILABLE,
    ]);
    createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'label' => 'ICU-2',
        'bed_class' => 'STANDARD',
        'features' => ['MONITOR'],
        'status' => Bed::STATUS_AVAILABLE,
    ]);

    /** @var list<BedSummary> $available */
    $available = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetAvailableBeds(
        facilityId: (string) $facility->id,
        bedClass: 'ICU_ELECTRIC',
        features: ['VENTILATOR'],
    )));

    expect(count($available))->toBe(1)
        ->and($available[0]->label)->toBe('ICU-1');
});

test('GetEncounterBed and GetBedOccupancyHistory return occupancy state', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id);

    ['encounterId' => $encounterId] =
        seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $occupancyId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AssignBed(
        occupancyId: $occupancyId,
        bedId: (string) $bed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    )));

    // Query encounter bed while ACTIVE
    $encounterBed = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetEncounterBed($encounterId)));

    expect($encounterBed['current'])->not->toBeNull()
        ->and($encounterBed['current']->occupancyId)->toBe($occupancyId)
        ->and($encounterBed['current']->status)->toBe('ACTIVE');

    // Release bed
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new ReleaseBed(
        occupancyId: $occupancyId,
        actorId: (string) $user->id,
        endReason: BedEndReason::DISCHARGE,
    )));

    // Query encounter bed after release
    $encounterBedAfter = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetEncounterBed($encounterId)));

    expect($encounterBedAfter['current'])->toBeNull()
        ->and(count($encounterBedAfter['history']))->toBe(1)
        ->and($encounterBedAfter['history'][0]->status)->toBe('ENDED');

    // Query bed history
    $bedHistory = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetBedOccupancyHistory((string) $bed->id)));

    expect(count($bedHistory))->toBe(1)
        ->and($bedHistory[0]->occupancyId)->toBe($occupancyId);
});

test('GetFacilityOccupancy computes aggregate statistics', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id, ['capacity' => 10]);
    createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'B-F1', 'status' => Bed::STATUS_AVAILABLE]);
    createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'B-F2', 'status' => Bed::STATUS_OCCUPIED]);

    /** @var FacilityOccupancySummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetFacilityOccupancy((string) $facility->id)));

    expect($summary->facilityId)->toBe((string) $facility->id)
        ->and($summary->totalBeds)->toBe(2)
        ->and($summary->occupiedBeds)->toBe(1)
        ->and($summary->availableBeds)->toBe(1)
        ->and($summary->occupancyRate)->toBe(50.0);
});
