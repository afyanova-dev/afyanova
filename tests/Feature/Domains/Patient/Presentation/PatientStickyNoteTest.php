<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Patient\Presentation;

use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientStickyNote;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function stickyNotePatient(string $tenantId): Patient
{
    return TenantContextScope::run($tenantId, null, fn (): Patient => Patient::factory()
        ->forTenant($tenantId)
        ->create([
            'given_name' => 'Amina',
            'family_name' => 'Juma',
            'display_name' => 'Amina Juma',
        ]));
}

it('creates, edits, archives and replaces the active patient sticky note', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'patient.patients.update',
    ]);
    $patient = stickyNotePatient((string) $tenant->id);
    $url = "/patient/{$patient->id}/sticky-note";
    $session = $this->actingAs($user)->withSession(['active_facility_id' => (string) $facility->id]);

    $session->getJson($url)->assertOk()->assertJsonPath('stickyNote', null);
    $session->postJson($url, ['body' => 'Call family before discharge'])
        ->assertCreated()
        ->assertJsonPath('stickyNote.body', 'Call family before discharge')
        ->assertJsonPath('stickyNote.author', $user->name);
    $session->postJson($url, ['body' => 'Do not create a duplicate'])
        ->assertStatus(409);

    $note = PatientStickyNote::query()->where('patient_id', (string) $patient->id)->firstOrFail();
    $session->patchJson("{$url}/{$note->id}", ['body' => 'Call family today'])
        ->assertOk()
        ->assertJsonPath('stickyNote.body', 'Call family today');

    $session->deleteJson("{$url}/{$note->id}")->assertOk()->assertJsonPath('archived', true);
    expect($note->fresh()->archived_at)->not->toBeNull();
    $auditActions = DB::table('shared_audit_log')
        ->where('target_type', 'patient_sticky_note')
        ->pluck('action')
        ->all();
    expect($auditActions)->toHaveCount(3)
        ->toContain('patient.sticky_note_created')
        ->toContain('patient.sticky_note_updated')
        ->toContain('patient.sticky_note_archived');
    $session->getJson($url)->assertOk()->assertJsonPath('stickyNote', null);

    $session->postJson($url, ['body' => 'Arrange transport'])
        ->assertCreated()
        ->assertJsonPath('stickyNote.body', 'Arrange transport');
});

it('enforces patient permissions, tenant isolation and note length', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'patient.patients.update',
    ]);
    $patient = stickyNotePatient((string) $tenant->id);
    $url = "/patient/{$patient->id}/sticky-note";
    $session = $this->actingAs($user)->withSession(['active_facility_id' => (string) $facility->id]);

    $session->postJson($url, ['body' => str_repeat('x', 301)])->assertUnprocessable();

    ['facility' => $otherFacility, 'user' => $otherUser] = makeTenantWithUser(['patient.patients.read']);
    $this->actingAs($otherUser)
        ->withSession(['active_facility_id' => (string) $otherFacility->id])
        ->getJson($url)
        ->assertNotFound();
});

it('does not allow users without patient update permission to create sticky notes', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
    ]);
    $patient = stickyNotePatient((string) $tenant->id);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->postJson("/patient/{$patient->id}/sticky-note", ['body' => 'Coordinate follow-up'])
        ->assertForbidden();
});
