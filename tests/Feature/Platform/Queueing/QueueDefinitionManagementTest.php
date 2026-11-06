<?php

declare(strict_types=1);

use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../../Support/queueing.php';

uses(RefreshDatabase::class);

test('facility administrators can create queue definitions', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.queues.store'), [
            'code' => 'triage',
            'name' => 'Nursing Triage',
            'department_id' => null,
            'service_capability' => 'TRIAGE',
            'ticket_prefix' => 'tri',
            'is_active' => true,
        ])
        ->assertRedirect(route('service-queues.manage'));

    $queue = TenantContextScope::run($tenantId, $facilityId, fn (): Queue => Queue::query()
        ->where('code', 'TRIAGE')
        ->firstOrFail());

    expect($queue->name)->toBe('Nursing Triage')
        ->and($queue->service_capability)->toBe('TRIAGE')
        ->and($queue->ticket_prefix)->toBe('TRI')
        ->and($queue->is_active)->toBeTrue();
});

test('facility administrators can update queue details without changing its code', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId, 'EDIT');

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.queues.update', ['queue' => $queue->id]), [
            'code' => 'CHANGED',
            'name' => 'Updated queue name',
            'department_id' => null,
            'service_capability' => 'CONSULTATION',
            'ticket_prefix' => 'OPD',
            'is_active' => false,
        ])
        ->assertRedirect(route('service-queues.manage'));

    $updatedQueue = TenantContextScope::run($tenantId, $facilityId, fn (): Queue => Queue::query()->findOrFail($queue->id));

    expect($updatedQueue->code)->toBe($queue->code)
        ->and($updatedQueue->name)->toBe('Updated queue name')
        ->and($updatedQueue->service_capability)->toBe('CONSULTATION')
        ->and($updatedQueue->ticket_prefix)->toBe('OPD')
        ->and($updatedQueue->is_active)->toBeFalse();
});

test('queue definition writes are forbidden without queue definition permission', function (): void {
    $fixture = queueingFixture(['queue.access']);

    $this->actingAs($fixture['user'])
        ->post(route('service-queues.queues.store'), [
            'code' => 'FORBIDDEN',
            'name' => 'Forbidden queue',
            'ticket_prefix' => 'F',
            'is_active' => true,
        ])
        ->assertForbidden();
});

test('queue definitions cannot be updated across facility boundaries', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $otherFacility = queueTestSecondFacility(
        $tenantId,
        (string) $fixture['organization']->id,
        (string) $fixture['user']->id,
        (string) $fixture['role_id'],
    );
    $queue = queueTestQueue($tenantId, (string) $otherFacility->id, 'OTHER');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => (string) $fixture['facility']->id])
        ->put(route('service-queues.queues.update', ['queue' => $queue->id]), [
            'name' => 'Cross-facility update',
            'department_id' => null,
            'service_capability' => null,
            'ticket_prefix' => 'O',
            'is_active' => true,
        ])
        ->assertNotFound();
});

test('queue definition validation rejects duplicate codes and unknown capabilities', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId, 'DUPLICATE');

    $this->actingAs($fixture['user'])
        ->from(route('service-queues.manage'))
        ->post(route('service-queues.queues.store'), [
            'code' => $queue->code,
            'name' => 'Duplicate queue',
            'department_id' => (string) Str::uuid7(),
            'service_capability' => 'UNSUPPORTED',
            'ticket_prefix' => 'D',
            'is_active' => true,
        ])
        ->assertSessionHasErrors(['code', 'department_id', 'service_capability']);
});

test('queue configuration is scoped to the current facility', function (): void {
    $fixture = queueingFixture(['queue.access', 'queue.definition.manage']);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = queueTestQueue($tenantId, $facilityId);
    $otherFacility = queueTestSecondFacility(
        $tenantId,
        (string) $fixture['organization']->id,
        (string) $fixture['user']->id,
        (string) $fixture['role_id'],
    );
    queueTestQueue($tenantId, (string) $otherFacility->id, 'OTHER');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get(route('service-queues.manage'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceQueues/Manage')
            ->has('queueDefinitions', 1)
            ->where('queueDefinitions.0.id', (string) $queue->id)
            ->has('queueRoomAssignments', 1));
});

test('queue viewers without definition permission cannot access queue configuration', function (): void {
    $fixture = queueingFixture(['queue.access', 'queue.ticket.view']);

    $this->actingAs($fixture['user'])
        ->get(route('service-queues.manage'))
        ->assertForbidden();
});
