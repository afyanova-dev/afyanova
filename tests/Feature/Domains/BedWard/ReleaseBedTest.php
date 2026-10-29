<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\AssignBed;
use App\Domains\BedWard\Application\Command\ReleaseBed;
use App\Domains\BedWard\Contracts\Event\BedReleased;
use App\Domains\BedWard\Domain\Exception\IllegalOccupancyTransition;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Domains\BedWard\Domain\Model\BedOccupancy;
use App\Domains\BedWard\Domain\ValueObject\BedEndReason;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it releases a bed: occupancy ended, bed transitions to CLEANING, event on outbox, audit written', function () {
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

    // Release bed
    $releaseCmd = new ReleaseBed(
        occupancyId: $occupancyId,
        actorId: (string) $user->id,
        endReason: BedEndReason::DISCHARGE,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($releaseCmd));

    // Occupancy is ended
    $occ = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedOccupancy::query()->find($occupancyId));
    expect($occ)->not->toBeNull()
        ->and($occ->status)->toBe(BedOccupancy::STATUS_ENDED)
        ->and($occ->end_reason)->toBe(BedEndReason::DISCHARGE)
        ->and($occ->ended_at)->not->toBeNull()
        ->and((int) $occ->version)->toBe(1);

    // Bed transitioned to CLEANING
    $freshBed = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($freshBed)->not->toBeNull()
        ->and($freshBed->status)->toBe(Bed::STATUS_CLEANING)
        ->and((int) $freshBed->version)->toBe(2);

    // Outbox event
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', BedReleased::class)
        ->first());

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('bed_ward.BedReleased');

    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['occupancyId'])->toBe($occupancyId)
        ->and($payload['bedId'])->toBe((string) $bed->id)
        ->and($payload['endReason'])->toBe(BedEndReason::DISCHARGE);

    // Audit log
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $occupancyId)
        ->where('action', 'bed_ward.bed_released')
        ->first());

    expect($audit)->not->toBeNull();
});

test('it rejects ReleaseBed on an already ended occupancy', function () {
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

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new ReleaseBed(
        occupancyId: $occupancyId,
        actorId: (string) $user->id,
        endReason: BedEndReason::DISCHARGE,
    )));

    // Second release attempt fails
    $cmd2 = new ReleaseBed(
        occupancyId: $occupancyId,
        actorId: (string) $user->id,
        endReason: BedEndReason::DISCHARGE,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cmd2)))
        ->toThrow(IllegalOccupancyTransition::class);
});
