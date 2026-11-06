<?php

declare(strict_types=1);

use App\Platform\Queueing\Application\Command\RegisterQueueCommand;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/queueing.php';

uses(RefreshDatabase::class);

test('queue registration records the target facility and actor in the audit log', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $code = 'AUDIT-QUEUE';

    $queue = TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new RegisterQueueCommand(
            facilityId: $facilityId,
            code: $code,
            name: 'Audit test queue',
            actorId: (string) $fixture['user']->id,
            serviceCapability: 'CONSULTATION',
        ),
    ));

    expect($queue->facilityId)->toBe($facilityId);

    $event = TenantContextScope::run($tenantId, null, fn () => DB::table('shared_audit_log')
        ->where('action', 'queue.configuration.saved')
        ->where('target_id', $code)
        ->first());

    expect($event)->not->toBeNull()
        ->and($event->tenant_id)->toBe($tenantId)
        ->and($event->facility_id)->toBe($facilityId)
        ->and($event->actor_id)->toBe((string) $fixture['user']->id)
        ->and($event->target_type)->toBe('queue');

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): bool => DB::table('qms_queues')->where('id', $queue->id)->exists(),
    ))->toBeTrue();
});

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
        ->assertRedirect(route('service-queues.manage'));

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.rooms.staff', [
            'queue' => $queue->id,
            'room' => $room->id,
        ]), [
            'staff_ids' => [$fixture['user']->id],
        ])
        ->assertRedirect(route('service-queues.manage'));

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
