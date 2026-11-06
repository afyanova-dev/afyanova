<?php

declare(strict_types=1);

use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('nursing vitals endpoint records measurements with server-assigned NURSING authorship', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        (string) $tenant->id,
        (string) $facility->id,
        (string) $user->id,
    );

    $response = $this->post(route('clinical.encounters.vitals.record', $encounterId), [
        'patient_id' => $patientId,
        'temperature' => '37.2',
        'heart_rate' => '82',
        'authored_context' => 'CLINICIAN',
    ]);

    $response->assertRedirect();
    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->first());
    expect($row)->not->toBeNull()
        ->and($row->authored_context)->toBe('NURSING')
        ->and((float) $row->temperature)->toBe(37.2)
        ->and((int) $row->heart_rate)->toBe(82);
});

test('nursing triage endpoint records coded priority with server-assigned NURSING authorship', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        (string) $tenant->id,
        (string) $facility->id,
        (string) $user->id,
    );

    $response = $this->post(route('clinical.encounters.triage.record', $encounterId), [
        'patient_id' => $patientId,
        'chief_complaint' => 'Fever and cough',
        'triage_priority' => 'urgent',
        'authored_context' => 'CLINICIAN',
    ]);

    $response->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('status', 'Triage assessment recorded.');
    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->first());
    expect($row)->not->toBeNull()
        ->and($row->authored_context)->toBe('NURSING')
        ->and($row->triage_priority)->toBe('URGENT')
        ->and($row->chief_complaint)->toBe('Fever and cough');
});

test('nursing documentation rejects malformed values before dispatch', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        (string) $tenant->id,
        (string) $facility->id,
        (string) $user->id,
    );

    $this->from('/workspaces/service-queues')->post(route('clinical.encounters.vitals.record', $encounterId), [
        'patient_id' => $patientId,
        'oxygen_saturation' => '101',
    ])->assertRedirect('/workspaces/service-queues')->assertSessionHasErrors('oxygen_saturation');

    $this->from('/workspaces/service-queues')->post(route('clinical.encounters.triage.record', $encounterId), [
        'patient_id' => $patientId,
        'chief_complaint' => 'Follow-up',
        'triage_priority' => 'UNRANKED',
    ])->assertRedirect('/workspaces/service-queues')->assertSessionHasErrors('triage_priority');

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_triage_assessments')->count()))->toBe(0);
});

test('nursing documentation rejects a patient and encounter mismatch', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [, $encounterId] = clinicalPatientWithEncounter(
        (string) $tenant->id,
        (string) $facility->id,
        (string) $user->id,
    );

    $this->from('/workspaces/service-queues')->post(route('clinical.encounters.vitals.record', $encounterId), [
        'patient_id' => (string) Str::uuid7(),
        'temperature' => '37.1',
    ])->assertRedirect('/workspaces/service-queues')->assertSessionHasErrors('vitals_error');

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(0);
});

test('nursing documentation returns an error without a selected facility', function (): void {
    $fixture = clinicalFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        $tenantId,
        $facilityId,
        (string) $fixture['user']->id,
    );

    TenantContextScope::run($tenantId, null, function () use ($fixture): void {
        DB::table('idn_roles')
            ->where('id', $fixture['role_id'])
            ->update(['scope_type' => 'ORG']);
        DB::table('idn_access_assignments')
            ->where('user_id', $fixture['user']->id)
            ->where('role_id', $fixture['role_id'])
            ->update([
                'facility_id' => null,
                'organization_id' => $fixture['organization']->id,
            ]);
    });

    $this->actingAs($fixture['user'])
        ->withSession(['facility_context_mode' => 'general'])
        ->post(route('clinical.encounters.vitals.record', $encounterId), [
            'patient_id' => $patientId,
            'temperature' => '37.1',
        ])
        ->assertSessionHasErrors('vitals_error');

    expect(Tenancy::run($tenantId, fn (): int => DB::table('clin_vital_sign_observations')->count()))
        ->toBe(0);
});

test('nursing documentation requires the specific record permission', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access',
        'clinical.vitals.read',
        'clinical.triage.read',
        'patient.patients.read',
        'patient.patients.create',
        'encounter.encounters.read',
        'encounter.encounters.create',
        'encounter.encounters.update',
    ]);
    [$patientId, $encounterId] = clinicalPatientWithEncounter(
        (string) $tenant->id,
        (string) $facility->id,
        (string) $user->id,
    );

    $this->post(route('clinical.encounters.vitals.record', $encounterId), [
        'patient_id' => $patientId,
        'temperature' => '37.1',
    ])->assertForbidden();

    $this->post(route('clinical.encounters.triage.record', $encounterId), [
        'patient_id' => $patientId,
        'chief_complaint' => 'Fever',
        'triage_priority' => 'NORMAL',
    ])->assertForbidden();
});
