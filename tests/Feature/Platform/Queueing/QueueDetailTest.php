<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Platform\Queueing\Application\Command\CallTicketCommand;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

// Slice pages unbuilt: disable missing-page enforcement so response shapes
// stay testable until the .tsx lands (then delete this block).
beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

test('the queue detail page renders with breakdown and entries', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId, 'DET');
    $entry = queueTestEntry($tenantId, $facilityId, (string) $queue->id);
    $room = queueTestRoom($tenantId, $facilityId);

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new CallTicketCommand(entryId: $entry->id, roomId: $room->id),
    ));

    $response = $this->actingAs($fixture['user'])
        ->get(route('service-queues.show', ['queue' => $queue->id]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('ServiceQueues/Show')
        ->where('queue.queue', $queue->name)
        ->where('breakdown.WAITING', 0)
        ->where('breakdown.CALLED', 1)
        ->has('waitingEntries', 0)
        ->has('calledEntries', 1)
        ->has('rooms', 1));

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): string => QueueEntry::query()->where('id', $entry->id)->value('status')->value,
    ))->toBe('CALLED');
});

test('the queue detail page requires the ticket view permission', function (): void {
    $fixture = queueingFixture(['queue.access']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId, 'NOV');

    $this->actingAs($fixture['user'])
        ->get(route('service-queues.show', ['queue' => $queue->id]))
        ->assertForbidden();
});

test('a queue id from another facility is not found', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityB = queueTestSecondFacility($tenantId, (string) $fixture['organization']->id, (string) $fixture['user']->id, (string) $fixture['role_id']);
    $queueB = queueTestQueue($tenantId, (string) $facilityB->id, 'FRQ');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => (string) $fixture['facility']->id])
        ->get(route('service-queues.show', ['queue' => $queueB->id]))
        ->assertNotFound();
});

test('guests are redirected to login from the queue detail page', function (): void {
    $this->get(route('service-queues.show', ['queue' => (string) Str::uuid7()]))
        ->assertRedirect(route('login'));
});
