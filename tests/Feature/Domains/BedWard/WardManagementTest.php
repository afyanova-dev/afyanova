<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\CreateWard;
use App\Domains\BedWard\Application\Command\DecommissionWard;
use App\Domains\BedWard\Application\Command\UpdateWard;
use App\Domains\BedWard\Contracts\Event\WardCreated;
use App\Domains\BedWard\Contracts\Event\WardDecommissioned;
use App\Domains\BedWard\Domain\Exception\WardCapabilityUnavailable;
use App\Domains\BedWard\Domain\Model\Ward;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it creates a ward: aggregate persisted, event on outbox, audit written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $wardId = (string) Str::uuid7();
    $command = new CreateWard(
        wardId: $wardId,
        facilityId: (string) $facility->id,
        actorId: (string) $user->id,
        code: 'MW-01',
        name: 'Male Medical Ward',
        wardType: 'GENERAL',
        capacity: 20,
        capabilityLink: 'INPATIENT_CARE',
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $ward = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Ward::query()->find($wardId));

    expect($ward)->not->toBeNull()
        ->and($ward->code)->toBe('MW-01')
        ->and($ward->name)->toBe('Male Medical Ward')
        ->and($ward->ward_type)->toBe('GENERAL')
        ->and($ward->capability_link)->toBe('INPATIENT_CARE')
        ->and((int) $ward->capacity)->toBe(20)
        ->and($ward->status)->toBe(Ward::STATUS_ACTIVE)
        ->and((int) $ward->version)->toBe(0);

    // Event on outbox
    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', WardCreated::class)
        ->first());

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('bed_ward.WardCreated');

    $payload = json_decode((string) $outbox->payload, true);
    expect($payload['wardId'])->toBe($wardId)
        ->and($payload['facilityId'])->toBe((string) $facility->id)
        ->and($payload['wardType'])->toBe('GENERAL');

    // Audit log
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $wardId)
        ->where('action', 'bed_ward.ward_created')
        ->first());

    expect($audit)->not->toBeNull()
        ->and((string) $audit->actor_id)->toBe((string) $user->id);
});

test('it rejects duplicate ward code within the same facility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    createWardFixture((string) $tenant->id, (string) $facility->id, ['code' => 'WARD-DUP']);

    $command = new CreateWard(
        wardId: (string) Str::uuid7(),
        facilityId: (string) $facility->id,
        actorId: (string) $user->id,
        code: 'WARD-DUP',
        name: 'Another Ward',
        wardType: 'GENERAL',
        capacity: 10,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(QueryException::class);
});

test('it rejects CreateWard if facility lacks required capability', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture(capabilities: ['OUTPATIENT_CARE']);

    $command = new CreateWard(
        wardId: (string) Str::uuid7(),
        facilityId: (string) $facility->id,
        actorId: (string) $user->id,
        code: 'WARD-NOCAP',
        name: 'No Cap Ward',
        wardType: 'GENERAL',
        capacity: 10,
        capabilityLink: 'ICU',
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(WardCapabilityUnavailable::class);
});

test('it updates ward details and increments version', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id, [
        'name' => 'Old Name',
        'ward_type' => 'GENERAL',
        'capacity' => 10,
    ]);

    $command = new UpdateWard(
        wardId: (string) $ward->id,
        actorId: (string) $user->id,
        name: 'New Name',
        wardType: 'SURGICAL',
        capacity: 25,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $fresh = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Ward::query()->find($ward->id));

    expect($fresh)->not->toBeNull()
        ->and($fresh->name)->toBe('New Name')
        ->and($fresh->ward_type)->toBe('SURGICAL')
        ->and((int) $fresh->capacity)->toBe(25)
        ->and((int) $fresh->version)->toBe(1);
});

test('it decommissions a ward and records WardDecommissioned event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);

    $command = new DecommissionWard(
        wardId: (string) $ward->id,
        actorId: (string) $user->id,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $fresh = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Ward::query()->find($ward->id));

    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBe(Ward::STATUS_DECOMMISSIONED)
        ->and((int) $fresh->version)->toBe(1);

    $outbox = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', WardDecommissioned::class)
        ->first());

    expect($outbox)->not->toBeNull()
        ->and($outbox->event_name)->toBe('bed_ward.WardDecommissioned');
});

test('CreateWard requires bed_ward.wards.configure permission', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture(permissions: ['bed_ward.access']);

    $command = new CreateWard(
        wardId: (string) Str::uuid7(),
        facilityId: (string) $facility->id,
        actorId: (string) $user->id,
        code: 'W-UNAUTH',
        name: 'Unauthorized Ward',
        wardType: 'GENERAL',
        capacity: 10,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(AuthorizationException::class);
});
