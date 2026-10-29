<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Patient\Presentation;

use App\Domains\Patient\Domain\Model\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
    Storage::fake('local');
});

function registerPhotoPatient(array $overrides = [], ?UploadedFile $photo = null): array
{
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.read',
    ]);

    $payload = array_merge([
        'given_name' => 'Amina',
        'family_name' => 'Juma',
        'sex' => 'FEMALE',
        'dob_precision' => 'KNOWN',
        'date_of_birth' => '1998-03-14',
    ], $overrides);

    if ($photo !== null) {
        $payload['photo'] = $photo;
    }

    return ['facility' => $facility, 'user' => $user, 'payload' => $payload];
}

it('registers a patient with a photo and serves it on the photo route', function (): void {
    ['facility' => $facility, 'user' => $user, 'payload' => $payload] = registerPhotoPatient(
        photo: UploadedFile::fake()->image('photo.jpg', 200, 200),
    );

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', $payload);

    $response->assertRedirect('/workspaces/patient');

    $patientId = (string) Patient::query()
        ->where('given_name', 'Amina')
        ->where('family_name', 'Juma')
        ->value('id');
    $patient = Patient::query()->whereKey($patientId)->first();
    expect($patient)->not->toBeNull()
        ->and($patient->photo_document_id)->not->toBeNull()
        ->and($patient->photo_captured_by)->toBe((string) $user->getKey())
        ->and($patient->photo_captured_at)->not->toBeNull();

    $document = DB::table('doc_documents')->where('id', $patient->photo_document_id)->first();
    expect($document)->not->toBeNull()
        ->and($document->doc_type)->toBe('PATIENT_PHOTO')
        ->and($document->patient_id)->toBe($patientId);

    $photoResponse = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get("/patient/{$patientId}/photo");

    $photoResponse->assertOk();
    expect($photoResponse->headers->get('Content-Type'))->toStartWith('image/');
});

it('exposes the photo url on the patient profile', function (): void {
    ['facility' => $facility, 'user' => $user, 'payload' => $payload] = registerPhotoPatient(
        photo: UploadedFile::fake()->image('photo.png', 100, 100),
    );

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', $payload);

    $patientId = (string) Patient::query()
        ->where('given_name', 'Amina')
        ->where('family_name', 'Juma')
        ->value('id');

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get("/patient/{$patientId}/chart/patient-summary")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Profile')
            ->where('photoUrl', url("/patient/{$patientId}/photo"))
        );
});

it('registers without a photo when none is supplied', function (): void {
    ['facility' => $facility, 'user' => $user, 'payload' => $payload] = registerPhotoPatient();

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', $payload);

    $response->assertRedirect();
    $patientId = (string) Patient::query()
        ->where('given_name', 'Amina')
        ->where('family_name', 'Juma')
        ->value('id');

    expect(Patient::query()->whereKey($patientId)->value('photo_document_id'))->toBeNull();

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get("/patient/{$patientId}/photo")
        ->assertNotFound();
});

it('rejects a non-image photo upload', function (): void {
    ['facility' => $facility, 'user' => $user, 'payload' => $payload] = registerPhotoPatient(
        photo: UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf'),
    );

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', $payload)
        ->assertSessionHasErrors('photo');
});

it('forbids photo access without the read permission', function (): void {
    ['facility' => $facility, 'user' => $user, 'payload' => $payload] = registerPhotoPatient(
        photo: UploadedFile::fake()->image('photo.jpg', 100, 100),
    );

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', $payload);

    $patientId = (string) Patient::query()
        ->where('given_name', 'Amina')
        ->where('family_name', 'Juma')
        ->value('id');

    ['user' => $other] = makeTenantWithUser([]);

    $this->actingAs($other)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get("/patient/{$patientId}/photo")
        ->assertForbidden();
});
