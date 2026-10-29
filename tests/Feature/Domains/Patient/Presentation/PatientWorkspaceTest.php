<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Patient\Presentation;

use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Patient\Domain\Enum\IdentifierType;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'inertia.pages.ensure_pages_exist' => false,
        'inertia.testing.ensure_pages_exist' => false,
    ]);
});

function seedTestPatient(
    string $tenantId,
    string $facilityId,
    string $givenName,
    string $familyName,
    ?string $phone = null,
    ?string $nationalId = null,
    ?string $insuranceId = null,
    bool $registerAtFacility = true,
    ?string $patientNumber = null,
): Patient {
    return TenantContextScope::run($tenantId, $facilityId, function () use ($tenantId, $facilityId, $givenName, $familyName, $phone, $nationalId, $insuranceId, $registerAtFacility, $patientNumber): Patient {
        $patientId = (string) Str::uuid7();
        $patient = Patient::factory()->forTenant($tenantId)->create([
            'id' => $patientId,
            'patient_number' => $patientNumber ?? 'PAT-'.fake()->unique()->numerify('########'),
            'given_name' => $givenName,
            'family_name' => $familyName,
            'display_name' => "{$givenName} {$familyName}",
            'primary_phone' => $phone,
            'sex' => Sex::Female,
            'date_of_birth' => '1992-04-10',
            'is_provisional' => false,
        ]);

        if ($nationalId !== null) {
            PatientIdentifier::factory()
                ->forTenant($tenantId)
                ->forPatient($patientId)
                ->ofType(IdentifierType::NationalId)
                ->withValue($nationalId)
                ->verified()
                ->create();
        }

        if ($insuranceId !== null) {
            PatientIdentifier::factory()
                ->forTenant($tenantId)
                ->forPatient($patientId)
                ->ofType(IdentifierType::InsuranceMember)
                ->withValue($insuranceId)
                ->verified()
                ->create();
        }

        if ($registerAtFacility) {
            PatientRegistration::factory()->forTenant($tenantId)->create([
                'patient_id' => $patientId,
                'facility_id' => $facilityId,
            ]);
        }

        return $patient;
    });
}

it('redirects unauthenticated guests to login on all patient workspace routes', function (): void {
    $this->get('/workspaces/patient')->assertRedirect('/login');
    $this->get('/workspaces/patient/register')->assertRedirect('/login');
    $this->post('/workspaces/patient/register', [])->assertRedirect('/login');
    $this->get('/workspaces/patient/'.(string) Str::uuid7())->assertRedirect('/login');
});

it('forbids users without patient.patients.read from directory and profile', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['encounter.encounters.read']);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient')
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient/'.(string) Str::uuid7())
        ->assertForbidden();
});

it('forbids users without patient.patients.create from register screen and submission', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient/register')
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Fatma',
            'family_name' => 'Ali',
            'sex' => 'FEMALE',
            'dob_precision' => 'KNOWN',
        ])
        ->assertForbidden();
});

it('forbids users without an active facility context', function (): void {
    ['tenant' => $tenant, 'organization' => $org, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);

    // Add a second facility assignment so neither is auto-selected
    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $org, $user): void {
        $fac2 = Facility::factory()->forTenant((string) $tenant->id, (string) $org->id)->create();
        $roleId = DB::table('idn_roles')->where('tenant_id', (string) $tenant->id)->value('id');
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => (string) $tenant->id,
            'user_id' => (string) $user->id,
            'role_id' => $roleId,
            'facility_id' => (string) $fac2->id,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($user)
        ->get('/workspaces/patient')
        ->assertForbidden();
});

it('forbids admin persona from patient workspace routes (least privilege)', function (): void {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'admin@afyanova.local')->firstOrFail();

    $this->actingAs($admin)
        ->get('/workspaces/patient')
        ->assertForbidden();

    $this->actingAs($admin)
        ->get('/workspaces/patient/register')
        ->assertForbidden();
});

it('allows reception persona to access patient directory', function (): void {
    $this->seed(DatabaseSeeder::class);
    $receptionist = User::where('email', 'reception@afyanova.local')->firstOrFail();

    $response = $this->actingAs($receptionist)
        ->get('/workspaces/patient');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients')
            ->where('searchError', null)
        );
});

it('renders patient search results when query matches', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $pat1 = seedTestPatient($tenantId, $facilityId, 'Amina', 'Hassan', '0711000111', registerAtFacility: true);
    $pat2 = seedTestPatient($tenantId, $facilityId, 'Baraka', 'Juma', '0722000222', registerAtFacility: false);

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?q=Hassan');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
            ->where('patients.0.patientId', (string) $pat1->id)
            ->where('patients.0.hasAccessibleFacilityRegistration', true)
        );
});

it('displays search criteria error when search query is too short', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient?q=Ha');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->where('patients', [])
            ->where('searchError', 'A name-fragment search needs at least 3 characters.')
        );
});

it('searches by specific identifiers', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $pat1 = seedTestPatient(
        $tenantId,
        $facilityId,
        'Amina',
        'Hassan',
        phone: '0711000111',
        nationalId: '12345678',
        insuranceId: 'SHA-998877',
        registerAtFacility: true,
        patientNumber: 'PAT-00000042',
    );

    // 1. Patient number (exact full MRN)
    $resp1 = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?patient_number='.$pat1->patient_number);

    $resp1->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 1b. Patient number (unpadded integer e.g. 42)
    $respInt = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?patient_number=42');

    $respInt->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 1c. Patient number (shorthand e.g. PAT-42 or #42)
    $respShort = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?patient_number=PAT-42');

    $respShort->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 1d. Main search bar auto-detection for integer patient number (q=42)
    $respQNumber = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?q=42');

    $respQNumber->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 1e. Main search bar auto-detection for phone (q=0711000111)
    $respQPhone = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?q=0711000111');

    $respQPhone->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 1f. Main search bar phone with spaces (q=0711 000 111)
    $respQPhoneSpaces = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?q=0711+000+111');

    $respQPhoneSpaces->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 1g. Main search bar auto-detection for National ID (q=12345678)
    $respQNational = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?q=12345678');

    $respQNational->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 1h. Main search bar auto-detection for Insurance ID (q=SHA-998877)
    $respQInsurance = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?q=SHA-998877');

    $respQInsurance->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 2. Patient number (partial suffix e.g. last 4 digits)
    $suffix = substr((string) $pat1->patient_number, -4);
    $respSuffix = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?patient_number='.$suffix);

    $respSuffix->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 3. National ID / Passport
    $respNational = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?national_id=12345678');

    $respNational->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 4. Insurance / Member ID
    $respInsurance = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?insurance_id=SHA-998877');

    $respInsurance->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 5. Phone number
    $resp2 = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?phone=0711000111');

    $resp2->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 6. Date of birth with name refinement
    $respDobRefined = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?q=Amina&dob=1992-04-10');

    $respDobRefined->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->has('patients', 1)
            ->where('patients.0.displayName', 'Amina Hassan')
        );

    // 7. DOB only (refinement explanation notice, no 422 crash)
    $resp3 = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/patient?dob=1992-04-10');

    $resp3->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Index')
            ->where('patients', [])
            ->where('searchError', 'Provide at least one of: exact patient number, exact national/insurance identifier, phone, or a name fragment.')
        );
});

it('persists and verifies national id and insurance member number when registering patient', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.read',
        'patient.identifiers.manage',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Baraka',
            'family_name' => 'Juma',
            'sex' => 'MALE',
            'dob_precision' => 'KNOWN',
            'date_of_birth' => '1988-11-20',
            'national_id' => '99887766',
            'insurance_member_number' => 'NHIF-554433',
        ]);

    $response->assertRedirect();

    $patient = DB::table('pat_patients')
        ->where('given_name', 'Baraka')
        ->where('family_name', 'Juma')
        ->first();

    expect($patient)->not->toBeNull();

    $identifiers = DB::table('pat_patient_identifiers')
        ->where('patient_id', $patient->id)
        ->get();

    expect($identifiers)->toHaveCount(2);

    $nationalRow = $identifiers->firstWhere('identifier_type', IdentifierType::NationalId->value);
    expect($nationalRow)->not->toBeNull()
        ->and($nationalRow->value)->toBe('99887766')
        ->and($nationalRow->verification_status)->toBe('VERIFIED');

    $insuranceRow = $identifiers->firstWhere('identifier_type', IdentifierType::InsuranceMember->value);
    expect($insuranceRow)->not->toBeNull()
        ->and($insuranceRow->value)->toBe('NHIF-554433')
        ->and($insuranceRow->verification_status)->toBe('VERIFIED');
});

it('renders register patient screen for authorized user', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.create']);

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient/register');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Register')
            ->where('duplicateReview', null)
            ->where('formValues', null)
        );
});

it('registers a new unique patient and redirects to the browse workspace', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.read',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Neema',
            'family_name' => 'Kondo',
            'sex' => 'FEMALE',
            'date_of_birth' => '1995-08-20',
            'dob_precision' => 'KNOWN',
            'primary_phone' => '0788123456',
        ]);

    // Registration completes without a 500: lands on the browse workspace
    // (chart/register pages are unbuilt; see TEMP in PatientRegistrationController).
    $response->assertRedirect('/workspaces/patient');
    $response->assertSessionHas('status');

    $this->assertDatabaseHas('pat_patients', [
        'given_name' => 'Neema',
        'family_name' => 'Kondo',
    ]);
});

it('returns duplicate review dialog when potential duplicate is detected', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    // Seed existing patient with exact same name and DOB
    seedTestPatient($tenantId, $facilityId, 'Zuwena', 'Bakari', phone: '0711999888');

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Zuwena',
            'family_name' => 'Bakari',
            'sex' => 'UNKNOWN', // name + DOB match with UNKNOWN sex -> score 75 -> REVIEW (ADR-117)
            'date_of_birth' => '1992-04-10',
            'dob_precision' => 'KNOWN',
            'primary_phone' => '0733444555',
            'override_duplicate' => false,
        ]);

    // REVIEW stays on the register form: review UI + repopulated values,
    // so the user can override in context instead of starting over.
    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Register')
            ->where('duplicateReview.outcome', 'REVIEW')
            ->has('duplicateReview.candidates')
            ->where('formValues.given_name', 'Zuwena')
            ->where('formValues.family_name', 'Bakari')
        );
});

it('blocks registration with flash error when exact identity collision (BLOCK) occurs', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    // Seed existing patient with exact same name, DOB, and sex -> score 85 -> BLOCK
    seedTestPatient($tenantId, $facilityId, 'Zuwena', 'Bakari', phone: '0711999888');

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Zuwena',
            'family_name' => 'Bakari',
            'sex' => 'FEMALE',
            'date_of_birth' => '1992-04-10',
            'dob_precision' => 'KNOWN',
            'primary_phone' => '0711999888',
            'override_duplicate' => false,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
    // Single home for the collision message: the DuplicateResolutionDialog
    // (duplicate_collision flash). It must NOT also land in the error bag,
    // or the form renders the same sentence under the field a second time.
    $response->assertSessionHas('duplicate_collision');
    $response->assertValid(['given_name', 'family_name', 'primary_phone', 'national_id', 'insurance_member_number', 'date_of_birth', 'registration_error']);
});

it('forbids overriding duplicate when user lacks override_duplicate permission', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create', // lacks patient.patients.override_duplicate
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    seedTestPatient($tenantId, $facilityId, 'Zuwena', 'Bakari', phone: '0711999888');

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Zuwena',
            'family_name' => 'Bakari',
            'sex' => 'FEMALE',
            'date_of_birth' => '1992-04-10',
            'dob_precision' => 'KNOWN',
            'primary_phone' => '0733444555',
            'override_duplicate' => true,
            'override_reason' => 'Different individual with identical names',
        ]);

    $response->assertForbidden();
});

it('registers patient when authorized user overrides duplicate with reason', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.override_duplicate',
        'patient.patients.read',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    seedTestPatient($tenantId, $facilityId, 'Zuwena', 'Bakari', phone: '0711999888');

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Zuwena',
            'family_name' => 'Bakari',
            'sex' => 'FEMALE',
            'date_of_birth' => '1992-04-10',
            'dob_precision' => 'KNOWN',
            'primary_phone' => '0733444555',
            'override_duplicate' => true,
            'override_reason' => 'Distinct patient, verified via national ID',
        ]);

    $response->assertRedirect('/workspaces/patient');
});

it('returns 404 for a non-existent patient profile', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser(['patient.patients.read']);

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->get('/workspaces/patient/'.(string) Str::uuid7());

    $response->assertNotFound();
});

it('returns 404 when viewing patient from another tenant (cross-tenant confinement)', function (): void {
    ['facility' => $facilityA, 'user' => $userA] = makeTenantWithUser(['patient.patients.read']);
    ['tenant' => $tenantB, 'facility' => $facilityB] = makeTenantWithUser(['patient.patients.read']);

    // Seed patient in Tenant B
    $patB = seedTestPatient((string) $tenantB->id, (string) $facilityB->id, 'Faraji', 'Mwenda');

    // User A in Tenant A attempts to view Patient B
    $response = $this->actingAs($userA)
        ->withSession(['active_facility_id' => (string) $facilityA->id])
        ->get('/workspaces/patient/'.(string) $patB->id);

    $response->assertNotFound();
});

it('binds Idempotency-Key header when registering patient', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.read',
    ]);

    $idempotencyKey = (string) Str::uuid();

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->withHeaders(['Idempotency-Key' => $idempotencyKey])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Juma',
            'family_name' => 'Rashid',
            'sex' => 'MALE',
            'date_of_birth' => '1990-01-15',
            'dob_precision' => 'KNOWN',
        ]);

    $response->assertRedirect();
});

it('registers patient with estimated age when dob_precision is ESTIMATED', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.read',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Amina',
            'family_name' => 'Juma',
            'sex' => 'FEMALE',
            'dob_precision' => 'ESTIMATED',
            'estimated_years' => 28,
            'estimated_months' => 4,
            'estimated_days' => 0,
        ]);

    $response->assertRedirect('/workspaces/patient');

    $patient = DB::table('pat_patients')
        ->where('given_name', 'Amina')
        ->where('family_name', 'Juma')
        ->first();
    expect($patient)->not->toBeNull();
    expect($patient->dob_precision)->toBe('ESTIMATED');
    expect($patient->date_of_birth)->toBe(now()->subYears(28)->subMonths(4)->toDateString());
});

it('returns rich inspection data for quick glance preview', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'patient.identifiers.view_sensitive',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $pat = seedTestPatient(
        $tenantId,
        $facilityId,
        'Amina',
        'Hassan',
        phone: '0711000111',
        nationalId: '12345678',
        insuranceId: 'SHA-998877',
        registerAtFacility: true,
        patientNumber: 'PAT-00000042',
    );

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->getJson("/workspaces/patient/{$pat->id}/glance");

    $response->assertOk()
        ->assertJson([
            'patientId' => (string) $pat->id,
            'patientNumber' => 'PAT-00000042',
            'displayName' => 'Amina Hassan',
            'sex' => 'FEMALE',
            'status' => 'ACTIVE',
            'isProvisional' => false,
            'dateOfBirth' => '1992-04-10',
            'primaryPhone' => '0711000111',
            'hasAccessibleFacilityRegistration' => true,
        ])
        ->assertJsonStructure([
            'age',
            'identifiers' => [
                '*' => ['type', 'label', 'value', 'isVerified'],
            ],
        ]);

    $data = $response->json();
    expect($data['identifiers'])->toHaveCount(2);
    expect($data['age'])->toContain('yrs');
});

it('renders patient profile with full 360 dossier structure', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'patient.identifiers.view_sensitive',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $pat = seedTestPatient(
        $tenantId,
        $facilityId,
        'Fatma',
        'Ali',
        phone: '0755123456',
        nationalId: '87654321',
        insuranceId: 'SHA-112233',
        registerAtFacility: true,
        patientNumber: 'PAT-00000099',
    );

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get("/workspaces/patient/{$pat->id}");

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Profile')
            ->has('patient', fn (Assert $patAssert) => $patAssert
                ->where('patientId', (string) $pat->id)
                ->where('patientNumber', 'PAT-00000099')
                ->where('displayName', 'Fatma Ali')
                ->where('givenName', 'Fatma')
                ->where('familyName', 'Ali')
                ->where('sex', 'FEMALE')
                ->where('primaryPhone', '0755123456')
                ->where('hasAccessibleFacilityRegistration', true)
                ->where('facilityRegistrationCount', 1)
                ->has('identifiers', 2)
                ->has('addresses')
                ->has('relationships')
                ->has('auditHistory')
                ->etc()
            )
            ->where('activeEncounter', null)
            ->has('availableQueues')
        );
});

it('passes activeEncounter to patient profile when patient has active attendance at facility', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'encounter.encounters.read',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $pat = seedTestPatient($tenantId, $facilityId, 'Khadija', 'Mussa');

    $encounter = TenantContextScope::run($tenantId, null, fn () => Encounter::factory()
        ->forTenant($tenantId)
        ->atFacility($facilityId)
        ->forPatient((string) $pat->id)
        ->ofType(EncounterType::Outpatient)
        ->withStatus(EncounterStatus::Arrived)
        ->create([
            'arrived_at' => now()->subMinutes(30),
        ]));

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->get("/workspaces/patient/{$pat->id}");

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Patients/Profile')
            ->where('activeEncounter.encounterId', (string) $encounter->id)
            ->where('activeEncounter.status', EncounterStatus::Arrived->value)
        );
});

it('updates patient demographics when valid reason is provided (ADR-127)', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read',
        'patient.patients.update',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $pat = seedTestPatient($tenantId, $facilityId, 'Baraka', 'Juma', phone: '0711222333');

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->patch("/workspaces/patient/{$pat->id}", [
            'given_name' => 'Barakah',
            'family_name' => 'Juma',
            'middle_name' => 'Rashid',
            'sex' => 'MALE',
            'date_of_birth' => '1990-05-20',
            'dob_precision' => 'KNOWN',
            'primary_phone' => '0711999000',
            'email' => 'barakah.juma@example.com',
            'reason' => 'Corrected spelling of given name and added middle name per national ID card.',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Patient demographics updated successfully.');

    $updated = DB::table('pat_patients')->where('id', $pat->id)->first();
    expect($updated->given_name)->toBe('Barakah');
    expect($updated->middle_name)->toBe('Rashid');
    expect($updated->display_name)->toBe('Barakah Juma');
    expect($updated->primary_phone)->toBe('0711999000');
    expect($updated->email)->toBe('barakah.juma@example.com');
});

it('rejects demographic mutation when reason is omitted for sensitive fields (ADR-127 Rule 5)', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.update',
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $pat = seedTestPatient($tenantId, $facilityId, 'Kassim', 'Omari');

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->patch("/workspaces/patient/{$pat->id}", [
            'given_name' => 'Cassim', // sensitive field changed
            'reason' => '', // missing reason!
        ]);

    $response->assertSessionHasErrors(['reason']);
});

it('forbids demographic update when user lacks patient.patients.update permission', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.read', // lacks update permission
    ]);
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;

    $pat = seedTestPatient($tenantId, $facilityId, 'Kassim', 'Omari');

    $response = $this->actingAs($user)
        ->withSession(['active_facility_id' => $facilityId])
        ->patch("/workspaces/patient/{$pat->id}", [
            'given_name' => 'Cassim',
            'reason' => 'Typo fix',
        ]);

    $response->assertForbidden();
});

it('registers without an address row when no address fields are sent', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.update',
    ]);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Neema',
            'family_name' => 'Kondo',
            'sex' => 'FEMALE',
            'date_of_birth' => '1995-08-20',
            'dob_precision' => 'KNOWN',
        ])
        ->assertRedirect('/workspaces/patient');

    $patientId = DB::table('pat_patients')
        ->where('given_name', 'Neema')
        ->where('family_name', 'Kondo')
        ->value('id');

    expect($patientId)->not->toBeNull();
    expect(DB::table('pat_patient_addresses')->where('patient_id', $patientId)->count())->toBe(0);
});

it('persists a complete residential address on registration', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.update',
    ]);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Amina',
            'family_name' => 'Juma',
            'sex' => 'FEMALE',
            'date_of_birth' => '1988-11-20',
            'dob_precision' => 'KNOWN',
            'region' => 'Dar es Salaam',
            'district' => 'Ilala',
            'ward' => 'Kariakoo',
            'street_or_village' => 'Uhuru Street',
            'postal_code' => '12101',
        ])
        ->assertRedirect('/workspaces/patient');

    $patientId = DB::table('pat_patients')
        ->where('given_name', 'Amina')
        ->where('family_name', 'Juma')
        ->value('id');

    $address = DB::table('pat_patient_addresses')->where('patient_id', $patientId)->first();
    expect($address)->not->toBeNull()
        ->and($address->type)->toBe('RESIDENTIAL')
        ->and($address->status)->toBe('ACTIVE')
        ->and($address->region)->toBe('Dar es Salaam')
        ->and($address->district)->toBe('Ilala')
        ->and($address->ward)->toBe('Kariakoo')
        ->and($address->street_or_village)->toBe('Uhuru Street')
        ->and($address->postal_code)->toBe('12101');
});

it('persists a partial address and skips silently without the update permission', function (): void {
    ['facility' => $facility, 'user' => $user] = makeTenantWithUser([
        'patient.patients.create',
        'patient.patients.update',
    ]);

    $this->actingAs($user)
        ->withSession(['active_facility_id' => (string) $facility->id])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Baraka',
            'family_name' => 'Juma',
            'sex' => 'MALE',
            'dob_precision' => 'UNKNOWN',
            'district' => 'Kinondoni',
        ])
        ->assertRedirect('/workspaces/patient');

    $patientId = DB::table('pat_patients')
        ->where('given_name', 'Baraka')
        ->where('family_name', 'Juma')
        ->value('id');

    $address = DB::table('pat_patient_addresses')->where('patient_id', $patientId)->first();
    expect($address)->not->toBeNull()
        ->and($address->district)->toBe('Kinondoni')
        ->and($address->region)->toBeNull()
        ->and($address->ward)->toBeNull();

    // Create-only user: registration succeeds, address silently skipped.
    ['facility' => $facility2, 'user' => $createOnly] = makeTenantWithUser(['patient.patients.create']);

    $this->actingAs($createOnly)
        ->withSession(['active_facility_id' => (string) $facility2->id])
        ->post('/workspaces/patient/register', [
            'given_name' => 'Zuwena',
            'family_name' => 'Bakari',
            'sex' => 'FEMALE',
            'dob_precision' => 'UNKNOWN',
            'district' => 'Temeke',
        ])
        ->assertRedirect('/workspaces/patient');

    $patientId2 = DB::table('pat_patients')
        ->where('given_name', 'Zuwena')
        ->where('family_name', 'Bakari')
        ->value('id');

    expect($patientId2)->not->toBeNull();
    expect(DB::table('pat_patient_addresses')->where('patient_id', $patientId2)->count())->toBe(0);
});
