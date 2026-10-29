<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/queueing.php';

uses(RefreshDatabase::class);

test('room and staff routing writes create target-facility audit events', function (): void {
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
        ->put(route('service-queues.queues.rooms.staff', [
            'queue' => $queue->id,
            'room' => $room->id,
        ]), [
            'staff_ids' => [$fixture['user']->id],
        ])
        ->assertRedirect(route('service-queues.index'));

    $events = TenantContextScope::run($tenantId, null, fn () => DB::table('shared_audit_log')
        ->where('action', 'like', 'queue.configuration.%')
        ->orderBy('action')
        ->get());

    expect($events)->toHaveCount(2)
        ->and($events->pluck('action')->all())->toBe([
            'queue.configuration.room_staff_updated',
            'queue.configuration.rooms_updated',
        ])
        ->and($events->pluck('facility_id')->unique()->all())->toBe([$facilityId])
        ->and($events->pluck('actor_id')->unique()->all())->toBe([(string) $fixture['user']->id]);
});
