<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Platform\Queueing\Application\Command\CallTicketCommand;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/queueing.php';

uses(RefreshDatabase::class);

test('room routing cannot remove the called room of an outstanding ticket', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $calledRoom = queueTestRoom($tenantId, $facilityId, 'CALLED-ROOM');
    $replacementRoom = queueTestRoom($tenantId, $facilityId, 'NEW-ROOM');
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $calledRoom->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $calledRoom->id, (string) $fixture['user']->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $replacementRoom->id, (string) $fixture['user']->id);

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new CallTicketCommand(entryId: (string) $entry->id, roomId: (string) $calledRoom->id),
    ));

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms', ['queue' => $queue->id]), [
            'room_ids' => [$replacementRoom->id],
        ])
        ->assertSessionHasErrors([
            'room_ids' => 'Keep the called room assigned until its outstanding tickets are resolved.',
        ]);

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): array => DB::table('qms_queue_rooms')
        ->where('queue_id', $queue->id)
        ->orderBy('room_id')
        ->pluck('room_id')
        ->all()))->toBe([$calledRoom->id]);
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => QueueEntry::query()
        ->whereKey($entry->id)
        ->value('status')
        ->value))->toBe('CALLED');
    expect(TenantContextScope::run($tenantId, null, fn (): int => DB::table('shared_audit_log')
        ->where('action', 'queue.configuration.rooms_updated')
        ->count()))->toBe(0);
});

test('waiting tickets remain valid when all service locations are removed', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $room = queueTestRoom($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $room->id, (string) $fixture['user']->id);

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms', ['queue' => $queue->id]), [
            'room_ids' => [],
        ])
        ->assertRedirect(route('service-queues.manage'));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('qms_queue_rooms')
        ->where('queue_id', $queue->id)
        ->where('room_id', $room->id)
        ->exists()))->toBeFalse();
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => QueueEntry::query()
        ->whereKey($entry->id)
        ->value('status')
        ->value))->toBe('WAITING');
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('qms_queue_room_staff')
        ->where('queue_id', $queue->id)
        ->where('room_id', $room->id)
        ->exists()))->toBeFalse();

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]))
        ->assertRedirect(route('service-queues.index'));

    $calledEntry = TenantContextScope::run($tenantId, $facilityId, fn (): QueueEntry => QueueEntry::query()
        ->whereKey($entry->id)
        ->firstOrFail());
    expect($calledEntry->status)->toBe(QueueStatus::CALLED)
        ->and($calledEntry->called_room_id)->toBeNull();

    expect(TenantContextScope::run($tenantId, null, fn (): int => DB::table('shared_audit_log')
        ->where('action', 'queue.configuration.rooms_updated')
        ->count()))->toBe(1);
});

test('waiting tickets can use a configured location without staff restrictions', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $room = queueTestRoom($tenantId, $facilityId);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms', ['queue' => $queue->id]), [
            'room_ids' => [$room->id],
        ])
        ->assertRedirect(route('service-queues.manage'));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('qms_queue_rooms')
        ->where('queue_id', $queue->id)
        ->where('room_id', $room->id)
        ->exists()))->toBeTrue();
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => QueueEntry::query()
        ->whereKey($entry->id)
        ->value('status')
        ->value))->toBe('WAITING');
});

test('staff restrictions can be cleared after their assigned staff member becomes inactive', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $inactiveStaff = makeUserWithPermissionsInTenant(
        $fixture['tenant'],
        $fixture['facility'],
        [],
    );
    $queue = queueTestQueue($tenantId, $facilityId);
    $room = queueTestRoom($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $room->id, (string) $inactiveStaff->id);
    TenantContextScope::run($tenantId, null, fn () => DB::table('users')
        ->where('id', $inactiveStaff->id)
        ->update(['status' => 'SUSPENDED']));

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new CallTicketCommand(entryId: (string) $entry->id, roomId: (string) $room->id),
    ));

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms.staff', [
            'queue' => $queue->id,
            'room' => $room->id,
        ]), [
            'staff_ids' => [],
        ])
        ->assertRedirect(route('service-queues.manage'));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('qms_queue_rooms')
        ->where('queue_id', $queue->id)
        ->where('room_id', $room->id)
        ->exists()))->toBeTrue();
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => QueueEntry::query()
        ->whereKey($entry->id)
        ->value('status')
        ->value))->toBe('CALLED');
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('qms_queue_room_staff')
        ->where('queue_id', $queue->id)
        ->where('room_id', $room->id)
        ->exists()))->toBeFalse();
    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.start', ['entry' => $entry->id]))
        ->assertRedirect(route('service-queues.index'));
});

test('waiting tickets can move to another service location without staff assignments', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $oldRoom = queueTestRoom($tenantId, $facilityId, 'OLD-ROOM');
    $newRoom = queueTestRoom($tenantId, $facilityId, 'NEW-ROOM');
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $oldRoom->id);
    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms', ['queue' => $queue->id]), [
            'room_ids' => [$newRoom->id],
        ])
        ->assertRedirect(route('service-queues.manage'));

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]), [
            'room' => $newRoom->id,
        ])
        ->assertRedirect(route('service-queues.index'));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): array => DB::table('qms_queue_rooms')
        ->where('queue_id', $queue->id)
        ->pluck('room_id')
        ->all()))->toBe([$newRoom->id]);
    $calledEntry = TenantContextScope::run($tenantId, $facilityId, fn (): QueueEntry => QueueEntry::query()
        ->whereKey($entry->id)
        ->firstOrFail());

    expect($calledEntry->status)->toBe(QueueStatus::CALLED)
        ->and($calledEntry->called_room_id)->toBe($newRoom->id);
});

test('staff restrictions can be cleared while a ticket is called to the location', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $room = queueTestRoom($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $room->id, (string) $fixture['user']->id);

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new CallTicketCommand(entryId: (string) $entry->id, roomId: (string) $room->id),
    ));

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms.staff', [
            'queue' => $queue->id,
            'room' => $room->id,
        ]), [
            'staff_ids' => [],
        ])
        ->assertRedirect(route('service-queues.manage'));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): bool => DB::table('qms_queue_room_staff')
        ->where('queue_id', $queue->id)
        ->where('room_id', $room->id)
        ->where('user_id', $fixture['user']->id)
        ->exists()))->toBeFalse();
    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => QueueEntry::query()
        ->whereKey($entry->id)
        ->value('status')
        ->value))->toBe('CALLED');
    expect(TenantContextScope::run($tenantId, null, fn (): int => DB::table('shared_audit_log')
        ->where('action', 'queue.configuration.room_staff_updated')
        ->count()))->toBe(1);

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.start', ['entry' => $entry->id]))
        ->assertRedirect(route('service-queues.index'));
});

test('replacing room staff revokes removed staff but keeps outstanding tickets serviceable', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $replacement = makeUserWithPermissionsInTenant(
        $fixture['tenant'],
        $fixture['facility'],
        ['queue.access', 'queue.ticket.view', 'queue.ticket.service'],
    );
    $queue = queueTestQueue($tenantId, $facilityId);
    $room = queueTestRoom($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $room->id, (string) $fixture['user']->id);

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new CallTicketCommand(entryId: (string) $entry->id, roomId: (string) $room->id),
    ));

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms.staff', [
            'queue' => $queue->id,
            'room' => $room->id,
        ]), [
            'staff_ids' => [$replacement->id],
        ])
        ->assertRedirect(route('service-queues.manage'));

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.start', ['entry' => $entry->id]))
        ->assertForbidden();

    $this->actingAs($replacement)
        ->post(route('service-queues.entries.start', ['entry' => $entry->id]))
        ->assertRedirect(route('service-queues.index'));
    $this->actingAs($replacement)
        ->post(route('service-queues.entries.complete', ['entry' => $entry->id]))
        ->assertRedirect(route('service-queues.index'));

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => QueueEntry::query()
        ->whereKey($entry->id)
        ->value('status')
        ->value))->toBe('COMPLETED');
});
