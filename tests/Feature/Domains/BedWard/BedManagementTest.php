<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\BlockBed;
use App\Domains\BedWard\Application\Command\CreateBed;
use App\Domains\BedWard\Application\Command\DecommissionBed;
use App\Domains\BedWard\Application\Command\MarkBedCleaning;
use App\Domains\BedWard\Application\Command\MarkBedReady;
use App\Domains\BedWard\Application\Command\UnblockBed;
use App\Domains\BedWard\Application\Command\UpdateBed;
use App\Domains\BedWard\Contracts\Event\BedCreated;
use App\Domains\BedWard\Contracts\Event\BedDecommissioned;
use App\Domains\BedWard\Domain\Exception\IllegalBedTransition;
use App\Domains\BedWard\Domain\Model\Bed;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it creates a bed: aggregate persisted, event on outbox, audit written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);

    $bedId = (string) Str::uuid7();
    $command = new CreateBed(
        bedId: $bedId,
        facilityId: (string) $facility->id,
        wardId: (string) $ward->id,
        roomId: null,
        actorId: (string) $user->id,
        label: 'BED-01',
        bedClass: 'STANDARD',
        features: ['OXYGEN', 'SUCTION'],
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $bed = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bedId));

    expect($bed)->not->toBeNull()
        ->and($bed->label)->toBe('BED-01')
        ->and($bed->bed_class)->toBe('STANDARD')
        ->and($bed->features)->toBe(['OXYGEN', 'SUCTION'])
        ->and($bed->status)->toBe(Bed::STATUS_AVAILABLE)
        ->and((int) $bed->version)->toBe(0);

    // Outbox event
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', BedCreated::class)
        ->first());

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('bed_ward.BedCreated');

    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['bedId'])->toBe($bedId)
        ->and($payload['wardId'])->toBe((string) $ward->id)
        ->and($payload['bedClass'])->toBe('STANDARD');

    // Audit log
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $bedId)
        ->where('action', 'bed_ward.bed_created')
        ->first());

    expect($audit)->not->toBeNull();
});

test('it rejects duplicate bed label in the same ward', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);

    createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, ['label' => 'BED-DUP']);

    $command = new CreateBed(
        bedId: (string) Str::uuid7(),
        facilityId: (string) $facility->id,
        wardId: (string) $ward->id,
        roomId: null,
        actorId: (string) $user->id,
        label: 'BED-DUP',
        bedClass: 'STANDARD',
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(QueryException::class);
});

test('it updates bed details', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'label' => 'BED-OLD',
        'bed_class' => 'STANDARD',
        'features' => [],
    ]);

    $command = new UpdateBed(
        bedId: (string) $bed->id,
        actorId: (string) $user->id,
        label: 'BED-NEW',
        bedClass: 'ICU_ELECTRIC',
        features: ['VENTILATOR', 'MONITOR'],
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $fresh = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));

    expect($fresh)->not->toBeNull()
        ->and($fresh->label)->toBe('BED-NEW')
        ->and($fresh->bed_class)->toBe('ICU_ELECTRIC')
        ->and($fresh->features)->toBe(['VENTILATOR', 'MONITOR'])
        ->and((int) $fresh->version)->toBe(1);
});

test('it blocks an available bed and then unblocks it', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id);

    // Block
    $blockCmd = new BlockBed(
        bedId: (string) $bed->id,
        actorId: (string) $user->id,
        reason: 'Broken rails',
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($blockCmd));

    $blocked = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($blocked)->not->toBeNull()
        ->and($blocked->status)->toBe(Bed::STATUS_BLOCKED);

    // Unblock
    $unblockCmd = new UnblockBed(
        bedId: (string) $bed->id,
        actorId: (string) $user->id,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($unblockCmd));

    $unblocked = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($unblocked)->not->toBeNull()
        ->and($unblocked->status)->toBe(Bed::STATUS_AVAILABLE);
});

test('it rejects blocking an occupied bed', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_OCCUPIED,
    ]);

    $blockCmd = new BlockBed(
        bedId: (string) $bed->id,
        actorId: (string) $user->id,
        reason: 'Attempt block on occupied',
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($blockCmd)))
        ->toThrow(IllegalBedTransition::class);
});

test('it transitions bed through cleaning and mark ready lifecycle', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_AVAILABLE,
    ]);

    // Mark Cleaning
    $cleanCmd = new MarkBedCleaning(
        bedId: (string) $bed->id,
        actorId: (string) $user->id,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cleanCmd));

    $cleaning = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($cleaning)->not->toBeNull()
        ->and($cleaning->status)->toBe(Bed::STATUS_CLEANING);

    // Mark Ready
    $readyCmd = new MarkBedReady(
        bedId: (string) $bed->id,
        actorId: (string) $user->id,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($readyCmd));

    $ready = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($ready)->not->toBeNull()
        ->and($ready->status)->toBe(Bed::STATUS_AVAILABLE);
});

test('it rejects marking an occupied bed as cleaning — the only sanctioned OCCUPIED to CLEANING path is ReleaseBed (post-audit fix)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_OCCUPIED,
    ]);

    $cleanCmd = new MarkBedCleaning(
        bedId: (string) $bed->id,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cleanCmd)))
        ->toThrow(IllegalBedTransition::class);

    $stillOccupied = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($stillOccupied)->not->toBeNull()
        ->and($stillOccupied->status)->toBe(Bed::STATUS_OCCUPIED);
});

test('it decommissions an available bed and rejects decommissioning an occupied bed', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);
    $bed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_AVAILABLE,
    ]);

    $cmd = new DecommissionBed(
        bedId: (string) $bed->id,
        actorId: (string) $user->id,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cmd));

    $decommissioned = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Bed::query()->find($bed->id));
    expect($decommissioned)->not->toBeNull()
        ->and($decommissioned->status)->toBe(Bed::STATUS_DECOMMISSIONED);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', BedDecommissioned::class)
        ->first());

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('bed_ward.BedDecommissioned');

    // Occupied bed cannot be decommissioned
    $occBed = createBedFixture((string) $tenant->id, (string) $facility->id, (string) $ward->id, [
        'status' => Bed::STATUS_OCCUPIED,
    ]);

    $failCmd = new DecommissionBed(
        bedId: (string) $occBed->id,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($failCmd)))
        ->toThrow(IllegalBedTransition::class);
});
