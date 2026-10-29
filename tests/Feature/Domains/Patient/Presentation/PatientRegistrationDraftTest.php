<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Patient\Presentation;

use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function registrationDraftFixture(array $permissions = ['patient.patients.create']): array
{
    $fixture = makeTenantWithUser($permissions);

    return [
        ...$fixture,
        'authenticated' => test()->actingAs($fixture['user'])
            ->withSession(['active_facility_id' => (string) $fixture['facility']->id]),
    ];
}

it('encrypts and restores a registration draft for its user and facility', function (): void {
    $fixture = registrationDraftFixture();
    $values = [
        'given_name' => 'Amina',
        'family_name' => 'Test',
        'sex' => 'FEMALE',
        'dob_precision' => 'UNKNOWN',
        'return_to_reception' => true,
        'ignored' => 'must not be stored',
    ];

    $fixture['authenticated']
        ->postJson('/workspaces/patient/register/draft', ['values' => $values])
        ->assertOk()
        ->assertJsonStructure(['savedAt', 'expiresAt']);

    $stored = TenantContextScope::run(
        (string) $fixture['tenant']->id,
        (string) $fixture['facility']->id,
        fn () => DB::table('pat_registration_drafts')->first(),
    );

    expect($stored)->not->toBeNull()
        ->and($stored->payload)->not->toContain('Amina')
        ->and(json_decode(Crypt::decryptString($stored->payload), true))->toBe([
            'given_name' => 'Amina',
            'family_name' => 'Test',
            'sex' => 'FEMALE',
            'dob_precision' => 'UNKNOWN',
            'return_to_reception' => true,
        ]);
    expect(TenantContextScope::run(
        (string) $fixture['tenant']->id,
        (string) $fixture['facility']->id,
        fn (): int => DB::table('pat_patients')->count(),
    ))->toBe(0);

    $fixture['authenticated']
        ->get('/workspaces/patient/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Register')
            ->where('hasSavedDraft', true)
            ->where('formValues.given_name', 'Amina')
            ->where('formValues.family_name', 'Test')
        );
});

it('does not expose a draft to another account at the same facility', function (): void {
    $fixture = registrationDraftFixture();
    $fixture['authenticated']->postJson('/workspaces/patient/register/draft', [
        'values' => ['given_name' => 'Amina'],
    ])->assertOk();

    $otherUser = User::factory()->forTenant($fixture['tenant']->id)->create();
    TenantContextScope::run((string) $fixture['tenant']->id, (string) $fixture['facility']->id, function () use ($fixture, $otherUser): void {
        DB::table('idn_staff_identities')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $fixture['tenant']->id,
            'user_id' => (string) $otherUser->id,
            'is_staff' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $fixture['tenant']->id,
            'user_id' => (string) $otherUser->id,
            'role_id' => $fixture['role_id'],
            'facility_id' => (string) $fixture['facility']->id,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($otherUser)
        ->withSession(['active_facility_id' => (string) $fixture['facility']->id])
        ->get('/workspaces/patient/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasSavedDraft', false)
            ->where('formValues', null)
        );
});

it('requires patient creation permission to save drafts', function (): void {
    $fixture = registrationDraftFixture(['patient.patients.read']);

    $fixture['authenticated']
        ->postJson('/workspaces/patient/register/draft', ['values' => ['given_name' => 'Amina']])
        ->assertForbidden();
});

it('deletes a registration draft after successful patient registration', function (): void {
    $fixture = registrationDraftFixture();
    $fixture['authenticated']->postJson('/workspaces/patient/register/draft', [
        'values' => ['given_name' => 'Amina'],
    ])->assertOk();

    $fixture['authenticated']->post('/workspaces/patient/register', [
        'given_name' => 'Amina',
        'family_name' => 'Test',
        'sex' => 'FEMALE',
        'dob_precision' => 'UNKNOWN',
    ])->assertRedirect();

    expect(TenantContextScope::run(
        (string) $fixture['tenant']->id,
        (string) $fixture['facility']->id,
        fn (): int => DB::table('pat_registration_drafts')->count(),
    ))->toBe(0);
});

it('purges expired registration drafts', function (): void {
    $fixture = registrationDraftFixture();
    $fixture['authenticated']->postJson('/workspaces/patient/register/draft', [
        'values' => ['given_name' => 'Amina'],
    ])->assertOk();

    TenantContextScope::run((string) $fixture['tenant']->id, null, function (): void {
        DB::table('pat_registration_drafts')->update(['expires_at' => now()->subSecond()]);
    });

    $this->artisan('patient:registration-drafts:purge')
        ->expectsOutput('Deleted 1 expired patient registration draft(s).')
        ->assertSuccessful();

    expect(TenantContextScope::run(
        (string) $fixture['tenant']->id,
        (string) $fixture['facility']->id,
        fn (): int => DB::table('pat_registration_drafts')->count(),
    ))->toBe(0);
});

it('does not restore an expired registration draft', function (): void {
    $fixture = registrationDraftFixture();
    $fixture['authenticated']->postJson('/workspaces/patient/register/draft', [
        'values' => ['given_name' => 'Amina'],
    ])->assertOk();

    TenantContextScope::run((string) $fixture['tenant']->id, null, function (): void {
        DB::table('pat_registration_drafts')->update(['expires_at' => now()->subSecond()]);
    });

    $fixture['authenticated']
        ->get('/workspaces/patient/register')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasSavedDraft', false)
            ->where('formValues', null)
        );

    expect(TenantContextScope::run(
        (string) $fixture['tenant']->id,
        (string) $fixture['facility']->id,
        fn (): int => DB::table('pat_registration_drafts')->count(),
    ))->toBe(0);
});
