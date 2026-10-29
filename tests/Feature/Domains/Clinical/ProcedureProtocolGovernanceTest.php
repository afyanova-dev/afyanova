<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Clinical;

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../../Support/clinical.php';

uses(RefreshDatabase::class);

test('clinical governance can approve and revoke a facility protocol with an audit trail', function (): void {
    $fixture = clinicalFixture([
        'clinical.access',
        'clinical.procedures.read',
        'clinical.procedures.protocol_manage',
        'clinical.procedures.protocol_request',
        'patient.patients.read',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'queue.ticket.place',
        'queue.ticket.view',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $actorId = (string) $fixture['user']->id;
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'GOVERNED-DRESSING',
        'name' => 'Governed wound dressing',
    ]);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/clinical/procedure-protocol-approvals', [
            'definition_id' => $definitionId,
            'protocol_reference' => 'WOUND-CARE-POLICY',
            'protocol_version' => '2026.2',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $approval = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_procedure_protocol_approvals')
        ->where('facility_id', $facilityId)
        ->where('procedure_definition_id', $definitionId)
        ->whereNull('revoked_at')
        ->first());
    expect($approval)->not->toBeNull()
        ->and($approval->protocol_reference)->toBe('WOUND-CARE-POLICY')
        ->and($approval->protocol_version)->toBe('2026.2')
        ->and((string) $approval->approved_by_user_id)->toBe($actorId)
        ->and((int) $approval->procedure_definition_version)->toBe(1);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post("/workspaces/clinical/procedure-protocol-approvals/{$approval->id}/revoke", [
            'reason' => 'Protocol superseded by the clinical governance committee.',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $revoked = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_procedure_protocol_approvals')
        ->where('id', $approval->id)
        ->first());
    expect($revoked->revoked_at)->not->toBeNull()
        ->and((string) $revoked->revoked_by_user_id)->toBe($actorId)
        ->and($revoked->revocation_reason)->toBe('Protocol superseded by the clinical governance committee.');

    $auditActions = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('shared_audit_log')
        ->whereIn('action', [
            'procedures.protocol_approval_granted',
            'procedures.protocol_approval_revoked',
        ])
        ->orderBy('action')
        ->pluck('action')
        ->all());
    expect($auditActions)->toBe([
        'procedures.protocol_approval_granted',
        'procedures.protocol_approval_revoked',
    ]);
});

test('protocol approval management is forbidden without its governance permission', function (): void {
    $fixture = clinicalFixture([
        'clinical.access',
        'clinical.procedures.read',
        'clinical.procedures.request',
        'patient.patients.read',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'queue.ticket.place',
    ]);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $definitionId = clinicalDefineProcedure($tenantId, [
        'code' => 'NO-GOVERNANCE-PERMISSION',
        'name' => 'Not approved',
    ]);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/clinical/procedure-protocol-approvals', [
            'definition_id' => $definitionId,
            'protocol_reference' => 'UNAPPROVED',
            'protocol_version' => '1',
        ])
        ->assertForbidden();

    expect(TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('clin_procedure_protocol_approvals')
        ->where('procedure_definition_id', $definitionId)
        ->exists()))->toBeFalse();
});
