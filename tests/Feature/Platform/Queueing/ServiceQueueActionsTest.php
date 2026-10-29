<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Platform\Queueing\Application\Command\CallTicketCommand;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Models\User;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('placing a queue entry creates a ticket and redirects with status', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);

    $response = $this->actingAs($fixture['user'])->post(route('service-queues.entries.store'), [
        'queue_id' => $queue->id,
        'encounter_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
        'priority' => 'URGENT',
    ]);

    $response->assertRedirect(route('service-queues.index'));
    $response->assertSessionHas('status');

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): int => QueueEntry::query()->where('queue_id', $queue->id)->count(),
    ))->toBe(1);
});

test('placing without the place permission is forbidden', function (): void {
    $fixture = queueingFixture(['queue.access', 'queue.ticket.view']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);

    $this->actingAs($fixture['user'])->post(route('service-queues.entries.store'), [
        'queue_id' => $queue->id,
        'encounter_id' => (string) Str::uuid7(),
        'patient_id' => (string) Str::uuid7(),
    ])->assertForbidden();
});

test('staff cannot forge order provenance when manually placing a procedure ticket', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Queue {
        $queue = queueTestQueue($tenantId, $facilityId);
        $queue->service_capability = 'PROCEDURE';
        $queue->save();

        return $queue;
    });

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.store'), [
            'queue_id' => $queue->id,
            'encounter_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'source_order_ref' => (string) Str::uuid7(),
            'order_type' => 'PROCEDURE_BOOKING',
        ])
        ->assertSessionHasErrors('place');

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): int => QueueEntry::query()->where('queue_id', $queue->id)->count(),
    ))->toBe(0);
});

test('the board requires the ticket view permission', function (): void {
    $fixture = queueingFixture(['queue.access']);

    $this->actingAs($fixture['user'])
        ->get(route('service-queues.index'))
        ->assertForbidden();
});

test('the board renders for a role holding the ticket view permission', function (): void {
    $fixture = queueingFixture(['queue.access', 'queue.ticket.view']);

    $this->actingAs($fixture['user'])
        ->get(route('service-queues.index'))
        ->assertOk();
});

test('the board presents called entries so service can be started', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    $room = queueTestRoom($tenantId, $facilityId);

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new CallTicketCommand(entryId: $entry->id, roomId: $room->id),
    ));

    $this->actingAs($fixture['user'])
        ->get(route('service-queues.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceQueues/Index')
            ->has('transferQueues', 1)
            ->has('calledEntries', 1)
            ->where('calledEntries.0.ticketNumber', $entry->ticket_number));
});

test('the board presents skipped and no-response tickets for recall', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $skipped = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    $noResponse = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    $room = queueTestRoom($tenantId, $facilityId);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $room->id, (string) $fixture['user']->id);

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.skip', ['entry' => $skipped->id]), ['reason' => 'Patient stepped away'])
        ->assertRedirect(route('service-queues.index'));
    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $noResponse->id]), ['room' => $room->id])
        ->assertRedirect(route('service-queues.index'));
    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.no-response', ['entry' => $noResponse->id]))
        ->assertRedirect(route('service-queues.index'));

    $this->actingAs($fixture['user'])
        ->get(route('service-queues.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('recallableEntries', 2)
            ->where('recallableEntries', fn ($entries): bool => collect($entries)
                ->pluck('status')
                ->sort()
                ->values()
                ->all() === ['NO_RESPONSE', 'SKIPPED']));

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.recall', ['entry' => $skipped->id]), ['room' => $room->id])
        ->assertRedirect(route('service-queues.index'));

    $status = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => QueueEntry::query()->where('id', $skipped->id)->value('status')->value,
    );
    expect($status)->toBe('CALLED');
});

test('call, start and complete move a ticket through service', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    $room = queueTestRoom($tenantId, $facilityId);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $room->id, (string) $fixture['user']->id);

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]), ['room' => $room->id])
        ->assertRedirect(route('service-queues.index'));

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.start', ['entry' => $entry->id]))
        ->assertRedirect(route('service-queues.index'));

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.complete', ['entry' => $entry->id]))
        ->assertRedirect(route('service-queues.index'));

    $status = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => QueueEntry::query()->where('id', $entry->id)->value('status')->value,
    );

    expect($status)->toBe('COMPLETED');
});

test('calling without a room fails validation', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]), [])
        ->assertSessionHasErrors('room');
});

test('a ticket can only be called to a room assigned to its queue', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId, 'OPD');
    $otherQueue = queueTestQueue($tenantId, $facilityId, 'TRI');
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    $assignedRoom = queueTestRoom($tenantId, $facilityId);
    $wrongRoom = queueTestRoom($tenantId, $facilityId, 'TRI-1');
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $assignedRoom->id);
    queueAssignRoom($tenantId, $facilityId, (string) $otherQueue->id, (string) $wrongRoom->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $assignedRoom->id, (string) $fixture['user']->id);

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]), ['room' => $wrongRoom->id])
        ->assertSessionHasErrors('room');

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]), ['room' => $assignedRoom->id])
        ->assertRedirect(route('service-queues.index'));
});

test('calling and starting service require assignment to the ticket room', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    $room = queueTestRoom($tenantId, $facilityId);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]), ['room' => $room->id])
        ->assertForbidden();

    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $room->id, (string) $fixture['user']->id);
    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]), ['room' => $room->id])
        ->assertRedirect(route('service-queues.index'));

    $otherUser = User::factory()->for($fixture['tenant'])->create();
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $otherUser, $fixture): void {
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'user_id' => $otherUser->id,
            'role_id' => $fixture['role_id'],
            'status' => 'ACTIVE',
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
    $this->actingAs($otherUser)
        ->post(route('service-queues.entries.start', ['entry' => $entry->id]))
        ->assertForbidden();

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.start', ['entry' => $entry->id]))
        ->assertRedirect(route('service-queues.index'));
});

test('queue administrators can configure and view queue room routing', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $room = queueTestRoom($tenantId, $facilityId);

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms', ['queue' => $queue->id]), [
            'room_ids' => [$room->id],
        ])
        ->assertRedirect(route('service-queues.index'));

    $this->actingAs($fixture['user'])
        ->get(route('service-queues.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('queueRoomAssignments.0.queueId', $queue->id)
            ->where('queueRoomAssignments.0.roomIds.0', $room->id));
});

test('queue room routing cannot be configured without queue definition permission', function (): void {
    $fixture = queueingFixture(['queue.access', 'queue.ticket.view']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms', ['queue' => $queue->id]), ['room_ids' => []])
        ->assertForbidden();
});

test('calling an entry that is already in service reports a domain error', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    $room = queueTestRoom($tenantId, $facilityId);
    queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);
    queueAssignRoomStaff($tenantId, $facilityId, (string) $queue->id, (string) $room->id, (string) $fixture['user']->id);

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]), ['room' => $room->id])
        ->assertRedirect();

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.start', ['entry' => $entry->id]))
        ->assertRedirect();

    // IN_SERVICE entries cannot be called again.
    $this->actingAs($fixture['user'])
        ->post(route('service-queues.entries.call', ['entry' => $entry->id]), ['room' => $room->id])
        ->assertSessionHasErrors('call');
});

test('transfer moves the ticket to another queue in the same facility', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $source = queueTestQueue($tenantId, $facilityId, 'SRC');
    $destination = queueTestQueue($tenantId, $facilityId, 'DST');
    $entry = queueTestEntry($tenantId, $facilityId, (string) $source->id);

    $this->actingAs($fixture['user'])->post(
        route('service-queues.entries.transfer', ['entry' => $entry->id]),
        ['destination_queue_id' => $destination->id, 'reason' => 'Doctor request'],
    )->assertRedirect(route('service-queues.index'));

    $queueId = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => QueueEntry::query()->where('encounter_id', $entry->encounter_id)
            ->where('status', 'WAITING')
            ->value('queue_id'),
    );

    expect($queueId)->toBe((string) $destination->id);
});

test('transferring to the same queue is rejected', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);

    $this->actingAs($fixture['user'])->post(
        route('service-queues.entries.transfer', ['entry' => $entry->id]),
        ['destination_queue_id' => (string) $queue->id],
    )->assertSessionHasErrors('destination_queue_id');
});

test('an entry id from another facility is not found', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityAId = (string) $fixture['facility']->id;
    $facilityB = queueTestSecondFacility($tenantId, (string) $fixture['organization']->id, (string) $fixture['user']->id, (string) $fixture['role_id']);
    $queueB = queueTestQueue($tenantId, (string) $facilityB->id, 'FRN');
    $entryB = queueTestEntry($tenantId, (string) $facilityB->id, (string) $queueB->id);

    // The user acts in facility A; B's entry id must 404, not leak.
    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityAId])
        ->post(route('service-queues.entries.start', ['entry' => $entryB->id]))
        ->assertNotFound();

    expect($facilityAId)->not->toBeEmpty();
});

test('hold and release round-trip with provenance', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);

    $this->actingAs($fixture['user'])->post(
        route('service-queues.entries.hold', ['entry' => $entry->id]),
        ['hold_reason' => 'CLINICAL_HOLD', 'hold_provenance' => 'Triage note #12'],
    )->assertRedirect(route('service-queues.index'));

    $this->actingAs($fixture['user'])
        ->get(route('service-queues.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('onHoldEntries', 1)
            ->where('onHoldEntries.0.id', $entry->id)
            ->where('onHoldEntries.0.holdReason', 'CLINICAL_HOLD'));

    $this->actingAs($fixture['user'])->post(
        route('service-queues.entries.release', ['entry' => $entry->id]),
        ['clearance_provenance' => 'Doctor clearance #7'],
    )->assertRedirect(route('service-queues.index'));

    $status = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => QueueEntry::query()->where('id', $entry->id)->value('status')->value,
    );

    expect($status)->toBe('WAITING');
});

test('pausing a queue requires the manage permission', function (): void {
    $fixture = queueingFixture(['queue.access', 'queue.ticket.view']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);

    $this->actingAs($fixture['user'])->post(
        route('service-queues.queues.pause', ['queue' => $queue->id]),
        ['reason' => 'Lunch break'],
    )->assertForbidden();
});

test('pausing and resuming a queue works with the manage permission', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);

    $this->actingAs($fixture['user'])->post(
        route('service-queues.queues.pause', ['queue' => $queue->id]),
        ['reason' => 'Cleaning'],
    )->assertRedirect(route('service-queues.index'));

    $this->actingAs($fixture['user'])->post(
        route('service-queues.queues.resume', ['queue' => $queue->id]),
    )->assertRedirect(route('service-queues.index'));

    $status = TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => Queue::query()->where('id', $queue->id)->value('operating_status')->value,
    );

    expect($status)->toBe('ACTIVE');
});
