<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Clinical;

use App\Domains\Clinical\Contracts\Event\ProcedureAuthorized;
use App\Platform\Queueing\Contracts\QueueingApi;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Events\EventRegistry;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../Support/clinical.php';
require_once __DIR__.'/../../../Support/queueing.php';

uses(RefreshDatabase::class);

test('clinicians can request and authorize a procedure, which routes an order-linked ticket', function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        $tenantId,
        $facilityId,
        (string) $fixture['user']->id,
    );
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'INJECTION',
        'name' => 'Injection',
    ]);
    $queue = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $definitionId): Queue {
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
        DB::table('qms_procedure_queue_mappings')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'procedure_definition_id' => $definitionId,
            'queue_id' => $queue->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $queue;
    });
    expect(TenantContextScope::run(
        $tenantId,
        $facilityId,
        fn (): bool => app(QueueingApi::class)->procedureQueueConfigured($facilityId, $definitionId),
    ))->toBeTrue();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.encounters.procedures.request', $encounterId), [
            'patient_id' => $patientId,
            'definition_id' => $definitionId,
            'indication' => 'Scheduled dose',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $procedureId = TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('clin_procedures')
        ->where('encounter_id', $encounterId)
        ->value('id'));

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.procedures.authorize', [
            'encounter' => $encounterId,
            'procedure' => $procedureId,
        ]))
        ->assertRedirect()
        ->assertSessionHas('status', 'Procedure authorized. It will be routed to its configured service queue.');

    $eventRow = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ProcedureAuthorized::class)
        ->first());
    expect($eventRow)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $eventRow->payload, true, 512, JSON_THROW_ON_ERROR);
    $event = new ProcedureAuthorized(...$payload);
    foreach (EventRegistry::listeners(ProcedureAuthorized::class) as $listenerClass) {
        app($listenerClass)($event);
    }

    $ticket = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('qms_queue_entries')
        ->where('source_order_ref', $procedureId)
        ->where('order_type', 'PROCEDURE_BOOKING')
        ->first());

    expect($ticket)->not->toBeNull()
        ->and($ticket->queue_id)->toBe($queue->id)
        ->and($ticket->encounter_id)->toBe($encounterId)
        ->and($ticket->patient_id)->toBe($patientId);
});

test('clinicians cannot authorize a procedure without a configured destination queue', function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        $tenantId,
        $facilityId,
        (string) $fixture['user']->id,
    );
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'DRESSING',
        'name' => 'Wound dressing',
    ]);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.encounters.procedures.request', $encounterId), [
            'patient_id' => $patientId,
            'definition_id' => $definitionId,
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $procedureId = TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('clin_procedures')
        ->where('encounter_id', $encounterId)
        ->value('id'));

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.procedures.authorize', [
            'encounter' => $encounterId,
            'procedure' => $procedureId,
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('authorize_error');

    expect(TenantContextScope::run($tenantId, $facilityId, fn (): string => (string) DB::table('clin_procedures')
        ->where('id', $procedureId)
        ->value('status')))->toBe('REQUESTED');
});
