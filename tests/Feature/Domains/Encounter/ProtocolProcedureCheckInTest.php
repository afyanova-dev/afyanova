<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Encounter;

use App\Domains\Clinical\Contracts\Event\ProcedureAuthorized;
use App\Platform\Queueing\Domain\Model\Queue;
use App\Shared\Events\EventRegistry;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../../Support/clinical.php';
require_once __DIR__.'/../../../Support/queueing.php';

uses(RefreshDatabase::class);

function approveProcedureProtocolForTest(
    string $tenantId,
    string $facilityId,
    string $definitionId,
    string $actorId,
    string $reference,
    string $version = '1.0',
    int $definitionVersion = 1,
): string {
    $approvalId = (string) Str::uuid7();
    TenantContextScope::run($tenantId, $facilityId, function () use (
        $tenantId,
        $facilityId,
        $definitionId,
        $actorId,
        $reference,
        $version,
        $definitionVersion,
        $approvalId,
    ): void {
        DB::table('clin_procedure_protocol_approvals')->insert([
            'id' => $approvalId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'procedure_definition_id' => $definitionId,
            'procedure_definition_version' => $definitionVersion,
            'protocol_reference' => $reference,
            'protocol_version' => $version,
            'approved_by_user_id' => $actorId,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    return $approvalId;
}

beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

test('Reception can check in a patient directly to an approved protocol procedure without clinician request permission', function (): void {
    $fixture = clinicalFixture([
        'clinical.access',
        'clinical.procedures.read',
        'clinical.procedures.protocol_request',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'encounter.encounters.transfer',
        'encounter.encounters.cancel',
        'queue.ticket.place',
        'queue.ticket.view',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $actorId = (string) $fixture['user']->id;
    $patientId = clinicalRegisterPatient($tenantId, $facilityId, $actorId)->patientId;
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'PROTOCOL-INJECTION',
        'name' => 'Protocol injection',
        'protocolReference' => 'FACILITY-PROTOCOL-IM-01',
    ]);
    $approvalId = approveProcedureProtocolForTest(
        $tenantId,
        $facilityId,
        $definitionId,
        $actorId,
        'FACILITY-PROTOCOL-IM-01',
        '2026.1',
    );
    $queue = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Queue {
        $queue = queueTestQueue($tenantId, $facilityId, 'DIRECT-PROC');
        $queue->service_capability = 'PROCEDURE';
        $queue->save();
        $room = queueTestRoom($tenantId, $facilityId, 'PROCEDURE-ROOM');
        queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);

        return $queue;
    });
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

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/reception')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('protocolProcedures.0.definitionId', $definitionId)
            ->where('protocolProcedures.0.queueId', (string) $queue->id)
            ->where('protocolProcedures.0.queueName', $queue->name)
            ->where('protocolProcedures.0.protocolVersion', '2026.1'));

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => 'PROCEDURE',
            'queue_id' => $queue->id,
            'protocol_procedure_definition_id' => $definitionId,
            'protocol_procedure_indication' => 'Scheduled protocol dose.',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $procedure = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_procedures')
        ->where('patient_id', $patientId)
        ->first());
    expect($procedure)->not->toBeNull()
        ->and($procedure->status)->toBe('AUTHORIZED')
        ->and($procedure->authored_context)->toBe('NURSING')
        ->and($procedure->authorization_basis)->toBe('APPROVED_PROTOCOL')
        ->and($procedure->authorization_protocol_reference)->toBe('FACILITY-PROTOCOL-IM-01')
        ->and($procedure->authorization_protocol_approval_id)->toBe($approvalId)
        ->and($procedure->authorization_protocol_version)->toBe('2026.1')
        ->and($procedure->indication)->toBe('Scheduled protocol dose.');

    $audit = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('shared_audit_log')
        ->where('action', 'procedures.protocol_authorized')
        ->where('target_id', $procedure->id)
        ->first());
    expect($audit)->not->toBeNull()
        ->and(json_decode((string) $audit->metadata, true)['protocol_reference'])
        ->toBe('FACILITY-PROTOCOL-IM-01');

    $eventRow = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ProcedureAuthorized::class)
        ->first());
    expect($eventRow)->not->toBeNull();
    $payload = json_decode((string) $eventRow->payload, true, 512, JSON_THROW_ON_ERROR);
    $event = new ProcedureAuthorized(...$payload);
    foreach (EventRegistry::listeners(ProcedureAuthorized::class) as $listenerClass) {
        app($listenerClass)($event);
    }

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('qms_queue_entries')
        ->where('source_order_ref', $procedure->id)
        ->where('queue_id', $queue->id)
        ->where('order_type', 'PROCEDURE_BOOKING')
        ->exists()))->toBeTrue();
});

test('Reception rejects a direct procedure definition without protocol approval', function (): void {
    $fixture = clinicalFixture([
        'clinical.access',
        'clinical.procedures.read',
        'clinical.procedures.protocol_request',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'encounter.encounters.transfer',
        'encounter.encounters.cancel',
        'queue.ticket.place',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $patientId = clinicalRegisterPatient($tenantId, $facilityId, (string) $fixture['user']->id)->patientId;
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'NOT-APPROVED',
        'name' => 'Not approved',
        'protocolReference' => 'LEGACY-CATALOG-REFERENCE',
    ]);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => 'PROCEDURE',
            'protocol_procedure_definition_id' => $definitionId,
        ])
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors('queue_id');

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('enc_encounters')
        ->where('patient_id', $patientId)
        ->exists()))->toBeFalse();
});

test('Reception cannot use a protocol-approved definition without the dedicated protocol permission', function (): void {
    $fixture = clinicalFixture([
        'clinical.access',
        'clinical.procedures.read',
        'clinical.procedures.request',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'encounter.encounters.transfer',
        'encounter.encounters.cancel',
        'queue.ticket.place',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $patientId = clinicalRegisterPatient($tenantId, $facilityId, (string) $fixture['user']->id)->patientId;
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'PROTOCOL-PERMISSION',
        'name' => 'Protocol gated procedure',
        'protocolReference' => 'APPROVED-ONLY',
    ]);
    approveProcedureProtocolForTest(
        $tenantId,
        $facilityId,
        $definitionId,
        (string) $fixture['user']->id,
        'APPROVED-ONLY',
    );
    $queue = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Queue {
        $queue = queueTestQueue($tenantId, $facilityId, 'PROTOCOL-GATED');
        $queue->service_capability = 'PROCEDURE';
        $queue->save();
        $room = queueTestRoom($tenantId, $facilityId, 'PROTOCOL-GATED-ROOM');
        queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);

        return $queue;
    });

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

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/reception')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('protocolProcedures', []));

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => 'PROCEDURE',
            'queue_id' => $queue->id,
            'protocol_procedure_definition_id' => $definitionId,
        ])
        ->assertForbidden();

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('enc_encounters')
        ->where('patient_id', $patientId)
        ->exists()))->toBeFalse();
});

test('Reception excludes stale and revoked protocol approvals from direct check-in', function (): void {
    $fixture = clinicalFixture([
        'clinical.access',
        'clinical.procedures.read',
        'clinical.procedures.protocol_request',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'encounter.encounters.transfer',
        'encounter.encounters.cancel',
        'queue.ticket.place',
        'queue.ticket.view',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $actorId = (string) $fixture['user']->id;
    $definitions = [
        'STALE-APPROVAL' => clinicalDefineProcedure($tenantId, [
            'code' => 'STALE-APPROVAL',
            'name' => 'Stale approval',
        ]),
        'REVOKED-APPROVAL' => clinicalDefineProcedure($tenantId, [
            'code' => 'REVOKED-APPROVAL',
            'name' => 'Revoked approval',
        ]),
    ];
    approveProcedureProtocolForTest(
        $tenantId,
        $facilityId,
        $definitions['STALE-APPROVAL'],
        $actorId,
        'STALE-POLICY',
        definitionVersion: 0,
    );
    $revokedApprovalId = approveProcedureProtocolForTest(
        $tenantId,
        $facilityId,
        $definitions['REVOKED-APPROVAL'],
        $actorId,
        'REVOKED-POLICY',
    );
    TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_procedure_protocol_approvals')
        ->where('id', $revokedApprovalId)
        ->update(['revoked_at' => now(), 'revoked_by_user_id' => $actorId]));

    $queue = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Queue {
        $queue = queueTestQueue($tenantId, $facilityId, 'STALE-PROC');
        $queue->service_capability = 'PROCEDURE';
        $queue->save();
        $room = queueTestRoom($tenantId, $facilityId, 'STALE-PROC-ROOM');
        queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);

        return $queue;
    });
    foreach ($definitions as $definitionId) {
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
    }

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/reception')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('protocolProcedures', []));

    $patientIds = [];
    foreach ($definitions as $definitionId) {
        $patientId = clinicalRegisterPatient($tenantId, $facilityId, $actorId)->patientId;
        $patientIds[] = $patientId;
        $this->actingAs($fixture['user'])
            ->withSession(['active_facility_id' => $facilityId])
            ->from('/workspaces/reception')
            ->post('/workspaces/reception/check-in', [
                'patient_id' => $patientId,
                'encounter_type' => 'PROCEDURE',
                'queue_id' => $queue->id,
                'protocol_procedure_definition_id' => $definitionId,
            ])
            ->assertRedirect('/workspaces/reception')
            ->assertSessionHasErrors('queue_id');
    }

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('enc_encounters')
        ->whereIn('patient_id', $patientIds)
        ->where('encounter_type', 'PROCEDURE')
        ->exists()))->toBeFalse();
});

test('Reception rejects an approved procedure when the submitted queue is not its current mapping', function (): void {
    $fixture = clinicalFixture([
        'clinical.access',
        'clinical.procedures.read',
        'clinical.procedures.protocol_request',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
        'encounter.encounters.manage_disposition',
        'encounter.encounters.complete',
        'encounter.encounters.transfer',
        'encounter.encounters.cancel',
        'queue.ticket.place',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $patientId = clinicalRegisterPatient($tenantId, $facilityId, (string) $fixture['user']->id)->patientId;
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'STALE-PROTOCOL-QUEUE',
        'name' => 'Stale mapped procedure',
        'protocolReference' => 'APPROVED-QUEUE-CHECK',
    ]);
    approveProcedureProtocolForTest(
        $tenantId,
        $facilityId,
        $definitionId,
        (string) $fixture['user']->id,
        'APPROVED-QUEUE-CHECK',
    );
    $mappedQueue = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Queue {
        $queue = queueTestQueue($tenantId, $facilityId, 'MAPPED-PROCEDURE');
        $queue->service_capability = 'PROCEDURE';
        $queue->save();
        $room = queueTestRoom($tenantId, $facilityId, 'MAPPED-PROCEDURE-ROOM');
        queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);

        return $queue;
    });
    $otherQueue = TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId): Queue {
        $queue = queueTestQueue($tenantId, $facilityId, 'OTHER-PROCEDURE');
        $queue->service_capability = 'PROCEDURE';
        $queue->save();
        $room = queueTestRoom($tenantId, $facilityId, 'OTHER-PROCEDURE-ROOM');
        queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);

        return $queue;
    });
    TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $definitionId, $mappedQueue): void {
        DB::table('qms_procedure_queue_mappings')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'procedure_definition_id' => $definitionId,
            'queue_id' => $mappedQueue->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from('/workspaces/reception')
        ->post('/workspaces/reception/check-in', [
            'patient_id' => $patientId,
            'encounter_type' => 'PROCEDURE',
            'queue_id' => $otherQueue->id,
            'protocol_procedure_definition_id' => $definitionId,
        ])
        ->assertRedirect('/workspaces/reception')
        ->assertSessionHasErrors('queue_id');

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('enc_encounters')
        ->where('patient_id', $patientId)
        ->exists()))->toBeFalse();
});
