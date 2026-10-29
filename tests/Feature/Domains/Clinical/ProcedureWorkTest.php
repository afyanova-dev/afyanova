<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Clinical;

use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../../../Support/clinical.php';
require_once __DIR__.'/../../../Support/problem.php';
require_once __DIR__.'/../../../Support/queueing.php';

uses(RefreshDatabase::class);

test('procedure queue staff can perform and document an authorized procedure', function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $actorId = (string) $fixture['user']->id;
    grantPermissionsToRole($tenantId, $fixture['role_id'], ['queue.ticket.view']);
    [$patientId, $encounterId] = clinicalPatientWithEncounter($tenantId, $facilityId, $actorId);
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'DRESSING',
        'name' => 'Wound Dressing',
        'requiresSite' => true,
        'requiresLaterality' => true,
        'requiresReadinessCheck' => true,
    ]);
    $procedureId = clinicalRequestProcedure($tenantId, $facilityId, $patientId, $encounterId, $actorId, [
        'definitionId' => $definitionId,
    ]);
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch(
        new AuthorizeProcedure($procedureId, $actorId),
    ));

    [$entryId, $queueId, $roomId] = TenantContextScope::run($tenantId, $facilityId, function () use (
        $tenantId,
        $facilityId,
        $patientId,
        $encounterId,
        $procedureId,
    ): array {
        $queue = queueTestQueue($tenantId, $facilityId, 'PROC-WORK');
        $queue->service_capability = 'PROCEDURE';
        $queue->save();
        $room = queueTestRoom($tenantId, $facilityId, 'PROC-WORK-1');
        queueAssignRoom($tenantId, $facilityId, (string) $queue->id, (string) $room->id);
        $entryId = (string) Str::uuid7();
        DB::table('qms_queue_entries')->insert([
            'id' => $entryId,
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'queue_id' => $queue->id,
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
            'ticket_number' => 'P-001',
            'priority' => 'ROUTINE',
            'status' => 'IN_SERVICE',
            'called_room_id' => $room->id,
            'source_order_ref' => $procedureId,
            'order_type' => 'PROCEDURE_BOOKING',
            'service_started_at' => now(),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$entryId, (string) $queue->id, (string) $room->id];
    });

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get(route('clinical.procedure-work.show', $entryId))
        ->assertForbidden();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.procedure-work.update', $entryId), ['workflow_action' => 'ready'])
        ->assertForbidden();

    queueAssignRoomStaff($tenantId, $facilityId, $queueId, $roomId, $actorId);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get(route('clinical.procedure-work.show', $entryId))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('ServiceQueues/ProcedureWork')
            ->where('procedure.status', 'AUTHORIZED')
            ->where('procedure.requiresReadinessCheck', true)
            ->where('woundCare.enabled', true)
            ->where('woundCare.canRecordAssessment', true)
            ->where('woundCare.materials', [])
            ->where('canPerform', true)
            ->where('canComplete', true));

    $url = route('clinical.procedure-work.update', $entryId);
    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post($url, ['workflow_action' => 'ready'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Procedure marked ready.');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post($url, [
            'workflow_action' => 'start',
            'site' => 'Left deltoid',
            'laterality' => 'LEFT',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Procedure started.');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post($url, [
            'workflow_action' => 'record-wound-assessment',
            'wound_assessment' => 'Wound assessed; findings documented.',
            'dressing_performed' => 'Wound cleaned and dressing applied.',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Wound assessment and dressing performed were documented.');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post($url, [
            'workflow_action' => 'record-wound-materials',
            'material_lines' => [
                ['material_ref' => 'Sterile gauze', 'quantity' => '2', 'unit' => 'pieces', 'batch_ref' => 'LOT-123'],
                ['material_ref' => 'Adhesive tape', 'quantity' => '1', 'unit' => 'roll', 'batch_ref' => ''],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Materials used were recorded.');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get(route('clinical.procedure-work.show', $entryId))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('woundCare.note.status', 'DRAFT')
            ->where('woundCare.materials.0.materialRef', 'Sterile gauze')
            ->where('woundCare.materials.1.materialRef', 'Adhesive tape'));

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post($url, [
            'workflow_action' => 'record-outcome',
            'outcome' => 'SUCCESSFUL',
            'outcome_detail' => 'Dose administered without issue.',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Procedure outcome saved.');

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post($url, [
            'workflow_action' => 'complete',
            'outcome' => 'SUCCESSFUL',
            'outcome_detail' => 'Dose administered without issue.',
            'complications' => 'None',
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Procedure completed and documented.');

    $procedure = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_procedures')
        ->where('id', $procedureId)
        ->first());
    expect($procedure->status)->toBe('COMPLETED')
        ->and($procedure->site)->toBe('Left deltoid')
        ->and($procedure->laterality)->toBe('LEFT')
        ->and($procedure->outcome)->toBe('SUCCESSFUL')
        ->and($procedure->outcome_detail)->toBe('Dose administered without issue.')
        ->and($procedure->complications)->toBe('None');

    $clinicalNote = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_notes')
        ->where('id', DB::table('clin_procedures')->where('id', $procedureId)->value('clinical_note_id'))
        ->first());
    expect($clinicalNote->authored_context)->toBe('NURSING')
        ->and($clinicalNote->body)->toContain(
            'Wound assessment:',
            'Wound assessed; findings documented.',
            'Dressing performed:',
            'Wound cleaned and dressing applied.',
        )
        ->and(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_procedure_materials')
            ->where('procedure_id', $procedureId)
            ->count()))->toBe(2);
});
