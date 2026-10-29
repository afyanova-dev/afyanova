<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Patient\Presentation;

use App\Domains\Patient\Domain\Model\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * Front Desk → Register → Save → Front Desk return flow.
 *
 * Entry carries `?return_to_reception=1` plus the board context
 * (`date`/`status`/`filter`); every value is re-validated at each hop.
 * Save redirects to the reception board with `patient_id` so the new
 * patient arrives preselected and check-in-ready. An invalid value is
 * dropped, never trusted.
 */

function returnFlowUser(): array
{
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.read',
    ]);

    return ['facility' => $facility, 'user' => $user];
}

function returnFlowPayload(array $overrides = []): array
{
    return array_merge([
        'given_name' => 'Rehema',
        'family_name' => 'Kweka',
        'sex' => 'FEMALE',
        'dob_precision' => 'KNOWN',
        'date_of_birth' => '1995-07-21',
    ], $overrides);
}

it('presets the reception return flag and board context on the register page', function (): void {
    ['facility' => $facility, 'user' => $user] = returnFlowUser();

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient/register?return_to_reception=1&date=2026-10-03&status=ACTIVE%2CBOOKED%2CARRIVED&filter=Jane')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Register')
            ->where('formValues.return_to_reception', true)
            ->where('formValues.return_date', '2026-10-03')
            ->where('formValues.return_status', 'BOOKED,ARRIVED,ACTIVE')
            ->where('formValues.return_filter', 'Jane')
            ->where('defaultAfterRegistration', 'FRONT_DESK')
        );
});

it('leaves form values empty without the reception return flag', function (): void {
    ['facility' => $facility, 'user' => $user] = returnFlowUser();

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Register')
            ->where('formValues', null)
            ->where('defaultAfterRegistration', 'PATIENT_LIST')
        );
});

it('defaults reception-capable registrars to Front Desk after registration', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.read',
        'encounter.encounters.create',
    ]);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Register')
            ->where('defaultAfterRegistration', 'FRONT_DESK'));
});

it('drops invalid board context on the register page', function (): void {
    ['facility' => $facility, 'user' => $user] = returnFlowUser();

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient/register?return_to_reception=1&date=2026-13-45&status=BOGUS')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Register')
            ->where('formValues.return_to_reception', true)
            ->missing('formValues.return_date')
            ->missing('formValues.return_status')
        );
});

it('returns to the front desk with the new patient preselected after save', function (): void {
    ['facility' => $facility, 'user' => $user] = returnFlowUser();

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', returnFlowPayload([
            'return_to_reception' => '1',
            'return_date' => '2026-10-03',
            'return_status' => 'ARRIVED',
            'return_filter' => 'Rehema',
        ]));

    $patientId = (string) Patient::query()
        ->where('given_name', 'Rehema')
        ->where('family_name', 'Kweka')
        ->value('id');
    expect($patientId)->not->toBe('');

    $response->assertRedirect(
        '/workspaces/reception?patient_id='.$patientId.'&date=2026-10-03&status=ARRIVED&filter=Rehema'
    );
});

it('ignores forged board context on save', function (): void {
    ['facility' => $facility, 'user' => $user] = returnFlowUser();

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', returnFlowPayload([
            'given_name' => 'Juma',
            'return_to_reception' => '1',
            'return_date' => 'not-a-date',
            'return_status' => 'BOGUS',
        ]));

    $patientId = (string) Patient::query()
        ->where('given_name', 'Juma')
        ->where('family_name', 'Kweka')
        ->value('id');
    expect($patientId)->not->toBe('');

    $response->assertRedirect('/workspaces/reception?patient_id='.$patientId);
});

it('opens the new patient chart when selected after registration', function (): void {
    ['facility' => $facility, 'user' => $user] = returnFlowUser();

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', returnFlowPayload([
            'given_name' => 'Amina',
            'family_name' => 'Test',
            'after_registration' => 'PATIENT_CHART',
        ]));

    $patientId = (string) Patient::query()
        ->where('given_name', 'Amina')
        ->where('family_name', 'Test')
        ->value('id');
    expect($patientId)->not->toBe('');

    $response->assertRedirect('/patient/'.$patientId.'/chart');
});
