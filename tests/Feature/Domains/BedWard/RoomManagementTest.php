<?php

declare(strict_types=1);

use App\Domains\BedWard\Application\Command\CreateRoom;
use App\Domains\BedWard\Domain\Model\Room;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it creates a room under a ward: row persisted, audit written', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);

    $roomId = (string) Str::uuid7();
    $command = new CreateRoom(
        roomId: $roomId,
        wardId: (string) $ward->id,
        actorId: (string) $user->id,
        name: 'Room 101',
        roomType: 'STANDARD',
        isolationCapable: true,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $room = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Room::query()->find($roomId));

    expect($room)->not->toBeNull()
        ->and($room->name)->toBe('Room 101')
        ->and($room->room_type)->toBe('STANDARD')
        ->and((bool) $room->isolation_capable)->toBeTrue()
        ->and((string) $room->ward_id)->toBe((string) $ward->id)
        ->and((string) $room->facility_id)->toBe((string) $facility->id);

    // Audit log
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $roomId)
        ->where('action', 'bed_ward.room_created')
        ->first());

    expect($audit)->not->toBeNull()
        ->and((string) $audit->actor_id)->toBe((string) $user->id);
});

test('it can create multiple rooms under the same ward', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture();

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);

    $cmd1 = new CreateRoom(
        roomId: (string) Str::uuid7(),
        wardId: (string) $ward->id,
        actorId: (string) $user->id,
        name: 'Room 101',
        roomType: 'STANDARD',
    );

    $cmd2 = new CreateRoom(
        roomId: (string) Str::uuid7(),
        wardId: (string) $ward->id,
        actorId: (string) $user->id,
        name: 'Room 102',
        roomType: 'ISOLATION',
        isolationCapable: true,
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($cmd1, $cmd2): void {
        app(CommandBus::class)->dispatch($cmd1);
        app(CommandBus::class)->dispatch($cmd2);
    });

    $count = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Room::query()->where('ward_id', (string) $ward->id)->count());
    expect($count)->toBe(2);
});

test('CreateRoom requires bed_ward.wards.configure permission', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = bedWardFixture(permissions: ['bed_ward.access']);

    $ward = createWardFixture((string) $tenant->id, (string) $facility->id);

    $command = new CreateRoom(
        roomId: (string) Str::uuid7(),
        wardId: (string) $ward->id,
        actorId: (string) $user->id,
        name: 'Unauthorized Room',
        roomType: 'STANDARD',
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(AuthorizationException::class);
});
