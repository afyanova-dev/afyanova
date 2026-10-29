<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\AssignBed;
use App\Domains\BedWard\Application\Command\ReleaseBed;
use App\Domains\BedWard\Contracts\BedWardApi;
use App\Domains\BedWard\Contracts\Dto\BedOccupancyRef;
use App\Domains\BedWard\Domain\ValueObject\BedEndReason;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('BedWardApi::activeOccupancyFor returns BedOccupancyRef when active and null when ended', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id);

    ['encounterId' => $encounterId] =
        seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $api = app(BedWardApi::class);

    // Prior to assignment -> null
    $refBefore = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => $api->activeOccupancyFor($encounterId));
    expect($refBefore)->toBeNull();

    // Assign bed
    $occupancyId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AssignBed(
        occupancyId: $occupancyId,
        bedId: (string) $bed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    )));

    // During active occupancy -> returns BedOccupancyRef
    /** @var BedOccupancyRef $refActive */
    $refActive = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => $api->activeOccupancyFor($encounterId));

    expect($refActive)->not->toBeNull()
        ->and($refActive->occupancyId)->toBe($occupancyId)
        ->and($refActive->bedId)->toBe((string) $bed->id)
        ->and($refActive->wardId)->toBe((string) $ward->id)
        ->and($refActive->facilityId)->toBe((string) $facility->id)
        ->and($refActive->encounterId)->toBe($encounterId);

    // Release bed
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new ReleaseBed(
        occupancyId: $occupancyId,
        actorId: (string) $user->id,
        endReason: BedEndReason::DISCHARGE,
    )));

    // After release -> null
    $refEnded = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => $api->activeOccupancyFor($encounterId));
    expect($refEnded)->toBeNull();
});
