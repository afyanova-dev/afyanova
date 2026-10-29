<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../../../tests/Support/hr.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
    Storage::fake('local');
});

it('stores a staff photo, exposes its authorized URL, and serves it from private document storage', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $photo = UploadedFile::fake()->image('staff-photo.jpg', 100, 100);
    $expectedContents = (string) $photo->get();

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('hr.staff.store'), [
            'first_name' => 'Amina',
            'last_name' => 'Mrema',
            'date_of_birth' => '1990-04-12',
            'gender' => 'FEMALE',
            'effective_from' => now()->toDateString(),
            'photo' => $photo,
        ])
        ->assertRedirect(route('hr.staff.index'));

    $staffRow = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('hr_staff')
        ->join('hr_persons', 'hr_persons.id', '=', 'hr_staff.person_id')
        ->where('hr_persons.first_name', 'Amina')
        ->where('hr_persons.last_name', 'Mrema')
        ->first(['hr_staff.id', 'hr_staff.person_id', 'hr_persons.photo_document_id']));
    expect($staffRow)->not->toBeNull()
        ->and($staffRow->photo_document_id)->not->toBeNull();

    $document = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('doc_documents')
        ->where('id', $staffRow->photo_document_id)
        ->first());
    expect($document)->not->toBeNull()
        ->and($document->doc_type)->toBe('STAFF_PHOTO')
        ->and($document->source_domain)->toBe('HR')
        ->and($document->source_entity_ref)->toBe((string) $staffRow->person_id)
        ->and($document->patient_id)->toBeNull();

    assignStaffHelper($tenantId, (string) $staffRow->id, $facilityId);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get(route('hr.staff.show', ['staff' => $staffRow->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('HR/Staff/Show')
            ->where('staff.photoUrl', route('hr.staff.photo', ['staff' => $staffRow->id])));

    $response = $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->get(route('hr.staff.photo', ['staff' => $staffRow->id]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('image/')
        ->and($response->getContent())->toBe($expectedContents);
});

it('allows a staff manager to replace the staff photo from the profile', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $personId = createPersonHelper(
        tenantId: $tenantId,
        firstName: 'Nuru',
        lastName: 'Nurse',
        facilityId: $facilityId,
    );
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    $photo = UploadedFile::fake()->image('replacement.png', 100, 100);

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->post(route('hr.staff.person.update', ['staff' => $staffId]), [
            '_method' => 'put',
            'first_name' => 'Nuru',
            'last_name' => 'Nurse',
            'date_of_birth' => '1990-05-15',
            'gender' => 'FEMALE',
            'photo' => $photo,
        ])
        ->assertRedirect(route('hr.staff.show', ['staff' => $staffId]));

    $documentId = TenantContextScope::run($tenantId, $facilityId, fn (): ?string => DB::table('hr_persons')
        ->where('id', $personId)
        ->value('photo_document_id'));
    expect($documentId)->not->toBeNull();

    $document = TenantContextScope::run($tenantId, $facilityId, fn () => DB::table('doc_documents')
        ->where('id', $documentId)
        ->first());
    expect($document?->doc_type)->toBe('STAFF_PHOTO')
        ->and($document?->source_entity_ref)->toBe($personId);
});

it('rejects non-image staff photos without creating a staff record', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $before = TenantContextScope::run($tenantId, $facilityId, fn (): int => DB::table('hr_staff')->count());

    $this->actingAs($fixture['user'])
        ->withSession(['active_facility_id' => $facilityId])
        ->from(route('hr.staff.create'))
        ->post(route('hr.staff.store'), [
            'first_name' => 'Amina',
            'last_name' => 'Mrema',
            'date_of_birth' => '1990-04-12',
            'gender' => 'FEMALE',
            'effective_from' => now()->toDateString(),
            'photo' => UploadedFile::fake()->create('staff-photo.pdf', 100, 'application/pdf'),
        ])
        ->assertSessionHasErrors('photo');

    $after = TenantContextScope::run($tenantId, $facilityId, fn (): int => DB::table('hr_staff')->count());
    expect($after)->toBe($before);
});

it('denies staff photo reads without HR staff read permission', function (): void {
    $fixture = hrFixture();
    $tenantId = (string) $fixture['tenant']->id;
    $facilityId = (string) $fixture['facility']->id;
    $personId = createPersonHelper($tenantId, facilityId: $facilityId);
    $staffId = createStaffHelper($tenantId, $personId, facilityId: $facilityId);
    assignStaffHelper($tenantId, $staffId, $facilityId);
    $unauthorized = User::factory()->forTenant($tenantId)->create([
        'email_verified_at' => now(),
        'status' => 'ACTIVE',
    ]);

    $this->actingAs($unauthorized)
        ->withSession(['active_facility_id' => $facilityId])
        ->get(route('hr.staff.photo', ['staff' => $staffId]))
        ->assertForbidden();
});
