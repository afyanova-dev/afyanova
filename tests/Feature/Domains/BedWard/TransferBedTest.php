<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\AssignBed;
use App\Domains\BedWard\Application\Command\TransferBed;
use App\Domains\BedWard\Contracts\Event\PatientTransferredBed;
use App\Domains\BedWard\Domain\Exception\BedNotAvailable;
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

test('it transfers a patient: old occupancy ended, old bed CLEANING, new occupancy ACTIVE, new bed OCCUPIED, event on outbox', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $fromBed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'FROM-BED']);
    $toBed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'TO-BED']);

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $oldOccupancyId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AssignBed(
        occupancyId: $oldOccupancyId,
        bedId: (string) $fromBed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    )));

    $newOccupancyId = (string) Str::uuid7();
    $transferCmd = new TransferBed(
        encounterId: $encounterId,
        fromBedId: (string) $fromBed->id,
        toBedId: (string) $toBed->id,
        newOccupancyId: $newOccupancyId,
        actorId: (string) $user->id,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($transferCmd));

    // Old occupancy is ENDED with TRANSFER
    $oldOcc = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedOccupancy::query()->find($oldOccupancyId));
    expect($oldOcc)->not->toBeNull()
        ->and($oldOcc->status)->toBe(BedOccupancy::STATUS_ENDED)
        ->and($oldOcc->end_reason)->toBe(BedEndReason::TRANSFER)
        ->and($oldOcc->ended_at)->not->toBeNull();

    // From-bed transitioned to CLEANING
    $freshFromBed = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($fromBed->id));
    expect($freshFromBed)->not->toBeNull()
        ->and($freshFromBed->status)->toBe(Bed::STATUS_CLEANING);

    // New occupancy is ACTIVE
    $newOcc = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => BedOccupancy::query()->find($newOccupancyId));
    expect($newOcc)->not->toBeNull()
        ->and($newOcc->status)->toBe(BedOccupancy::STATUS_ACTIVE)
        ->and((string) $newOcc->bed_id)->toBe((string) $toBed->id)
        ->and((string) $newOcc->encounter_id)->toBe($encounterId)
        ->and((string) $newOcc->patient_id)->toBe($patientId);

    // To-bed transitioned to OCCUPIED
    $freshToBed = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($toBed->id));
    expect($freshToBed)->not->toBeNull()
        ->and($freshToBed->status)->toBe(Bed::STATUS_OCCUPIED);

    // Outbox event
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PatientTransferredBed::class)
        ->first());

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('bed_ward.PatientTransferredBed');

    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['encounterId'])->toBe($encounterId)
        ->and($payload['fromBedId'])->toBe((string) $fromBed->id)
        ->and($payload['toBedId'])->toBe((string) $toBed->id)
        ->and($payload['fromOccupancyId'])->toBe($oldOccupancyId)
        ->and($payload['toOccupancyId'])->toBe($newOccupancyId);
});

test('it rejects transfer if destination bed is not available', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $fromBed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'FROM-BED']);
    $toBed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'label' => 'TO-BED',
        'status' => Bed::STATUS_OCCUPIED,
    ]);

    ['encounterId' => $encounterId] =
        seedActiveInpatientEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $oldOccupancyId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AssignBed(
        occupancyId: $oldOccupancyId,
        bedId: (string) $fromBed->id,
        encounterId: $encounterId,
        actorId: (string) $user->id,
    )));

    $transferCmd = new TransferBed(
        encounterId: $encounterId,
        fromBedId: (string) $fromBed->id,
        toBedId: (string) $toBed->id,
        newOccupancyId: (string) Str::uuid7(),
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($transferCmd)))
        ->toThrow(BedNotAvailable::class);
});
