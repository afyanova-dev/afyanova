<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\SignClinicalNote;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/clinical.php';

test('a clinician can append an amendment to a saved signed note', function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $actorId = (string) $fixture['user']->id;
    [$patientId, $encounterId] = clinicalPatientWithEncounter($tenantId, $facilityId, $actorId);
    $noteId = clinicalCreateNote($tenantId, $facilityId, $patientId, $encounterId, $actorId);
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new SignClinicalNote($noteId, $actorId)));

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.notes.amend', $noteId), [
            'amendment_type' => 'CORRECTION',
            'body' => 'Blood pressure corrected to 128/82.',
            'reason' => 'Transcription error.',
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    $amendment = Tenancy::run($tenantId, fn () => DB::table('clin_note_amendments')
        ->where('clinical_note_id', $noteId)
        ->first());
    expect($amendment)->not->toBeNull()
        ->and($amendment->amendment_type)->toBe('CORRECTION')
        ->and($amendment->body)->toBe('Blood pressure corrected to 128/82.')
        ->and($amendment->reason)->toBe('Transcription error.')
        ->and(Tenancy::run($tenantId, fn () => DB::table('clin_notes')
            ->where('id', $noteId)
            ->value('amendment_count')))->toBe(1);
});

test('an amendment requires its clinical reason', function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $actorId = (string) $fixture['user']->id;
    [$patientId, $encounterId] = clinicalPatientWithEncounter($tenantId, $facilityId, $actorId);
    $noteId = clinicalCreateNote($tenantId, $facilityId, $patientId, $encounterId, $actorId);
    TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)
        ->dispatch(new SignClinicalNote($noteId, $actorId)));

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('clinical.notes.amend', $noteId), [
            'amendment_type' => 'ADDENDUM',
            'body' => 'Additional information.',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('reason');

    expect(Tenancy::run($tenantId, fn () => DB::table('clin_note_amendments')
        ->where('clinical_note_id', $noteId)
        ->count()))->toBe(0);
});
