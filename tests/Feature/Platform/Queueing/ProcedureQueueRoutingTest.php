<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Queueing;

use App\Platform\Queueing\Application\Command\RouteAuthorizedProcedureToQueue;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../../Support/clinical.php';
require_once __DIR__.'/../../../Support/queueing.php';

uses(RefreshDatabase::class);

test('queue administrators can map active procedure definitions to procedure queues', function (): void {
    $fixture = queueingFixture([
        'queue.access',
        'queue.ticket.view',
        'queue.definition.manage',
        'clinical.procedures.read',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'DRESSING',
        'name' => 'Wound Dressing',
    ]);

    $queue = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Queue {
        $queue = queueTestQueue($tenantId, $facilityId, 'PROC');
        $queue->service_capability = 'PROCEDURE';
        $queue->save();
        $room = queueTestRoom($tenantId, $facilityId);
        DB::table('qms_queue_rooms')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'queue_id' => $queue->id,
            'room_id' => $room->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $queue;
    });

    $this->actingAs($fixture['user'])
        ->put(route('service-queues.procedure-routing'), [
            'mappings' => [[
                'definition_id' => $definitionId,
                'queue_id' => $queue->id,
            ]],
        ])
        ->assertRedirect(route('service-queues.index'))
        ->assertSessionHas('status', 'Procedure queue routing updated.');

    $this->actingAs($fixture['user'])
        ->get(route('service-queues.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('procedureRouting.definitions.0.definitionId', $definitionId)
            ->where('procedureRouting.definitions.0.queueId', (string) $queue->id)
            ->where('procedureRouting.queues.0.queueId', (string) $queue->id));

    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): int => DB::table('qms_procedure_queue_mappings')
            ->where('procedure_definition_id', $definitionId)
            ->where('queue_id', $queue->id)
            ->count(),
    ))->toBe(1);

    $auditEvent = TenantContextScope::run($tenantId, null, fn () => DB::table('shared_audit_log')
        ->where('action', 'queue.configuration.procedure_routing_updated')
        ->first());

    expect($auditEvent?->facility_id)->toBe($facilityId)
        ->and($auditEvent?->actor_id)->toBe((string) $fixture['user']->id)
        ->and(json_decode($auditEvent->metadata, true, 512, JSON_THROW_ON_ERROR))->toBe(['mapping_count' => 1]);
});

test('authorized procedure routing issues an idempotent order-linked ticket per procedure', function (): void {
    $fixture = queueingFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $queue = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Queue {
        $queue = queueTestQueue($tenantId, $facilityId, 'PROC');
        $queue->service_capability = 'PROCEDURE';
        $queue->save();
        $room = queueTestRoom($tenantId, $facilityId);
        DB::table('qms_queue_rooms')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'queue_id' => $queue->id,
            'room_id' => $room->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $queue;
    });
    $definitionId = (string) Str::uuid7();
    $encounterId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $procedureId = (string) Str::uuid7();
    $secondProcedureId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $definitionId, $queue): void {
        DB::table('qms_procedure_queue_mappings')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'procedure_definition_id' => $definitionId,
            'queue_id' => $queue->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $route = new RouteAuthorizedProcedureToQueue(
        eventId: (string) Str::uuid7(),
        facilityId: $facilityId,
        patientId: $patientId,
        encounterId: $encounterId,
        procedureId: $procedureId,
        definitionId: $definitionId,
    );

    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($route));
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($route));
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new RouteAuthorizedProcedureToQueue(
            eventId: (string) Str::uuid7(),
            facilityId: $facilityId,
            patientId: $patientId,
            encounterId: $encounterId,
            procedureId: $secondProcedureId,
            definitionId: $definitionId,
        ),
    ));

    $entries = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('qms_queue_entries')
        ->where('order_type', 'PROCEDURE_BOOKING')
        ->get());

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('source_order_ref')->all())->toContain($procedureId, $secondProcedureId)
        ->and($entries->pluck('queue_id')->unique()->all())->toBe([$queue->id])
        ->and($entries->pluck('encounter_id')->unique()->all())->toBe([$encounterId])
        ->and($entries->pluck('patient_id')->unique()->all())->toBe([$patientId]);
});
