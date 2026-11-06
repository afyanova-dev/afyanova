<?php

declare(strict_types=1);

use App\Shared\Tenancy\Tenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/clinical.php';

test('a clinician can sign a saved draft from the encounter workspace', function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $actorId = (string) $fixture['user']->id;
    [$patientId, $encounterId] = clinicalPatientWithEncounter($tenantId, $facilityId, $actorId);
    $noteId = clinicalCreateNote($tenantId, $facilityId, $patientId, $encounterId, $actorId);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.notes.sign', $noteId))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(Tenancy::run($tenantId, fn () => DB::table('clin_notes')->where('id', $noteId)->value('status')))
        ->toBe('SIGNED')
        ->and(Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')
            ->where('action', 'clinical.note_signed')
            ->where('target_id', $noteId)
            ->count()))->toBe(1);
});

test('signing a saved note is forbidden without the sign permission', function (): void {
    $permissions = [
        'clinical.access',
        'clinical.notes.create',
        'clinical.notes.read',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
    ];
    $fixture = clinicalFixture($permissions);
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $actorId = (string) $fixture['user']->id;
    [$patientId, $encounterId] = clinicalPatientWithEncounter($tenantId, $facilityId, $actorId);
    $noteId = clinicalCreateNote($tenantId, $facilityId, $patientId, $encounterId, $actorId);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.notes.sign', $noteId))
        ->assertForbidden();

    expect(Tenancy::run($tenantId, fn () => DB::table('clin_notes')->where('id', $noteId)->value('status')))
        ->toBe('DRAFT');
});
