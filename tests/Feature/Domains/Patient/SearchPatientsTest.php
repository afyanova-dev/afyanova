<?php

declare(strict_types=1);

use App\Domains\Organization\Domain\Model\Facility;
use App\Domains\Patient\Application\Query\SearchPatients;
use App\Domains\Patient\Contracts\Dto\PatientSearchResult;
use App\Domains\Patient\Domain\Enum\MergeHistoryStatus;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Exception\SearchCriteriaTooBroad;
use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Domains\Patient\Domain\Model\PatientIdentityHistory;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 5 (increment A.7) gate — SearchPatients end-to-end through the real
 | QueryBus / #[ProtectedQuery] pipeline (10-patient-domain.md §16, §52,
 | §126–§128, §131; ADR-114, ADR-123, ADR-129). Backend query only — the HTTP
 | route + rate-limiter are PHASE 16.
 */

uses(RefreshDatabase::class);

/**
 * @return array{tenant: mixed, organization: mixed, facility: mixed, user: mixed}
 */
function searchFixture(array $permissions = ['patient.patients.read']): array
{
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] = makeTenantWithUser($permissions);
    test()->actingAs($user);

    return ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user];
}

/** Promote the fixture user's single assignment to a tenant-wide one (org + facility NULL). */
function makeCallerTenantWide(string $tenantId, string $userId): void
{
    Tenancy::run($tenantId, fn () => DB::table('idn_access_assignments')
        ->where('user_id', $userId)
        ->update(['facility_id' => null, 'organization_id' => null]));
}

function seedSearchPatient(string $tenantId, array $attributes = [], bool $registerAtDefaultFacility = true): string
{
    return TenantContextScope::run($tenantId, null, function () use ($tenantId, $attributes, $registerAtDefaultFacility): string {
        $patient = Patient::factory()->forTenant($tenantId)->create($attributes);
        $facilityId = Facility::query()->value('id');
        if ($registerAtDefaultFacility && is_string($facilityId)) {
            PatientRegistration::factory()->forTenant($tenantId)
                ->forPatient((string) $patient->id)
                ->atFacility($facilityId)
                ->create();
        }

        return (string) $patient->id;
    });
}

function seedRegistration(string $tenantId, string $patientId, string $facilityId): void
{
    TenantContextScope::run($tenantId, null, fn () => PatientRegistration::factory()
        ->forTenant($tenantId)->forPatient($patientId)->atFacility($facilityId)->create());
}

function seedVerifiedIdentifier(string $tenantId, string $patientId, string $value): void
{
    TenantContextScope::run($tenantId, null, fn () => PatientIdentifier::factory()
        ->forTenant($tenantId)->forPatient($patientId)->withValue($value)->verified()->create());
}

/**
 * @return LengthAwarePaginator<int, PatientSearchResult>
 */
function dispatchSearch(string $tenantId, ?string $facilityId, SearchPatients $query): LengthAwarePaginator
{
    /** @var LengthAwarePaginator<int, PatientSearchResult> */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch($query));
}

/**
 * @param  LengthAwarePaginator<int, PatientSearchResult>  $results
 * @return list<string>
 */
function resultIds(LengthAwarePaginator $results): array
{
    return array_map(static fn (PatientSearchResult $r): string => $r->patientId, $results->items());
}

function searchedAuditRows(string $tenantId): Collection
{
    return Tenancy::run($tenantId, fn () => DB::table('shared_audit_log')->where('action', 'patient.searched')->get());
}

// ── authorization ─────────────────────────────────────────────────────

test('an authorized caller (patient.patients.read) gets results; an unauthorized caller is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture([]); // no permissions

    seedSearchPatient((string) $tenant->id, ['family_name' => 'Mkwawa', 'display_name' => 'Asha Mkwawa']);

    expect(fn () => dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'Mkwawa')))
        ->toThrow(AuthorizationException::class);
});

test('SearchPatients uses the existing patient.patients.read key — no separate search permission', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture(['patient.patients.read']);

    seedSearchPatient((string) $tenant->id, ['family_name' => 'Mkwawa', 'display_name' => 'Asha Mkwawa']);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'Mkwawa'));
    expect($results->total())->toBe(1);
});

// ── criteria floor (ADR-129 C) ────────────────────────────────────────

test('a criteria-free query is rejected with SearchCriteriaTooBroad (422) at construction', function () {
    expect(fn () => new SearchPatients)->toThrow(SearchCriteriaTooBroad::class);
    expect(fn () => new SearchPatients(nameFragment: 'Jo'))->toThrow(SearchCriteriaTooBroad::class);
    expect(fn () => new SearchPatients(dateOfBirth: '1990-05-01'))->toThrow(SearchCriteriaTooBroad::class);
});

// ── tenant isolation ──────────────────────────────────────────────────

test('the search set is the caller tenant MPI only — a same-number patient in another tenant is invisible', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA] = searchFixture();
    ['tenant' => $tenantB] = makeTenantWithUser([]);

    seedSearchPatient((string) $tenantB->id, ['patient_number' => 'PAT-90000001', 'family_name' => 'Otieno']);

    $results = dispatchSearch((string) $tenantA->id, (string) $facilityA->id, new SearchPatients(patientNumber: 'PAT-90000001'));
    expect($results->total())->toBe(0);
});

// ── criterion types (ADR-129 C) ──────────────────────────────────────

test('an exact patient-number match folds punctuation and case', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();
    $id = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00000042', 'family_name' => 'Zawadi']);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(patientNumber: 'pat 00000042'));
    expect(resultIds($results))->toBe([$id]);
});

test('a patient-number match works with unpadded integers and short sequences', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();
    $id = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00000042', 'family_name' => 'Zawadi']);

    // integer '42'
    $resInt = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(patientNumber: '42'));
    expect(resultIds($resInt))->toBe([$id]);

    // partial padded '042'
    $resPartial = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(patientNumber: '042'));
    expect(resultIds($resPartial))->toBe([$id]);

    // shorthand 'PAT-42'
    $resShort = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(patientNumber: 'PAT-42'));
    expect(resultIds($resShort))->toBe([$id]);

    // hash prefix '#42'
    $resHash = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(patientNumber: '#42'));
    expect(resultIds($resHash))->toBe([$id]);
});

test('an exact VERIFIED national-identifier match is found; an UNVERIFIED identifier is not', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    $verifiedId = seedSearchPatient((string) $tenant->id, ['family_name' => 'Haruna']);
    seedVerifiedIdentifier((string) $tenant->id, $verifiedId, '19900101-55555');

    $unverifiedId = seedSearchPatient((string) $tenant->id, ['family_name' => 'Salehe']);
    TenantContextScope::run((string) $tenant->id, null, fn () => PatientIdentifier::factory()
        ->forTenant((string) $tenant->id)->forPatient($unverifiedId)->withValue('20000202-66666')->create()); // UNVERIFIED

    $hit = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nationalId: '19900101 55555'));
    expect(resultIds($hit))->toBe([$verifiedId]);

    $miss = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nationalId: '20000202 66666'));
    expect($miss->total())->toBe(0);
});

test('a phone match is on normalized digits', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();
    $id = seedSearchPatient((string) $tenant->id, ['family_name' => 'Bakari', 'primary_phone' => '+255 (713) 000-111']);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(phone: '0713000111x'));
    // digits only: '0713000111' vs stored '255713000111' -> no match; exact-digits contract
    expect($results->total())->toBe(0);

    $exact = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(phone: '255713000111'));
    expect(resultIds($exact))->toBe([$id]);
});

test('a name fragment matches a case-insensitive substring of the folded name', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();
    $id = seedSearchPatient((string) $tenant->id, ['given_name' => 'Neema', 'family_name' => 'Al-Kimaro', 'display_name' => 'Neema Al-Kimaro']);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'kimaro'));
    expect(resultIds($results))->toBe([$id]);
});

// ── ranking (ADR-129 D) ──────────────────────────────────────────────

test('exact identifier / number rank ahead of loose name matching, phone between them', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    $numberHit = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00000700', 'family_name' => 'Xylophone']);
    $identifierHit = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00000800', 'family_name' => 'Yankee']);
    seedVerifiedIdentifier((string) $tenant->id, $identifierHit, 'NID-500-500');
    $phoneHit = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00000900', 'family_name' => 'Zulu', 'primary_phone' => '0788400400']);
    $nameHit = seedSearchPatient((string) $tenant->id, ['family_name' => 'Namematch', 'display_name' => 'A Namematch']);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(
        patientNumber: 'PAT-00000700',
        nationalId: 'NID 500 500',
        phone: '0788400400',
        nameFragment: 'namematch',
    ));

    expect(resultIds($results))->toBe([$numberHit, $identifierHit, $phoneHit, $nameHit]);
});

test('name-fragment tiers: family prefix > given/display prefix > substring', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    $familyPrefix = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00001001', 'given_name' => 'John', 'family_name' => 'Mkwawa', 'display_name' => 'John Mkwawa']);
    $givenPrefix = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00001002', 'given_name' => 'Mkwawa', 'family_name' => 'Zulu', 'display_name' => 'Mkwawa Zulu']);
    $substring = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00001003', 'given_name' => 'Bob', 'family_name' => 'Alimkwawa', 'display_name' => 'Bob Alimkwawa']);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'mkw'));
    expect(resultIds($results))->toBe([$familyPrefix, $givenPrefix, $substring]);
});

test('a matching supplied date of birth bumps a row up within its tier', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    $noDob = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00002001', 'family_name' => 'Kimaro', 'date_of_birth' => '1980-01-01']);
    $dobMatch = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00002002', 'family_name' => 'Kimaro', 'date_of_birth' => '1990-05-05']);

    // Without the DOB, the lower patient_number (noDob) would rank first.
    $plain = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'kimaro'));
    expect(resultIds($plain))->toBe([$noDob, $dobMatch]);

    $refined = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'kimaro', dateOfBirth: '1990-05-05'));
    expect(resultIds($refined))->toBe([$dobMatch, $noDob]);
});

test('the final tie-break is patient_number ascending', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    $second = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00003002', 'family_name' => 'Tiebreak']);
    $first = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00003001', 'family_name' => 'Tiebreak']);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'tiebreak'));
    expect(resultIds($results))->toBe([$first, $second]);
});

// ── pagination (ADR-129 / IC §6.3) ───────────────────────────────────

test('per-page is clamped to the ADR-129 maximum of 100', function () {
    $query = new SearchPatients(nameFragment: 'anything', perPage: 500);
    expect($query->perPage)->toBe(100)
        ->and(SearchPatients::MAX_PER_PAGE)->toBe(100);
});

test('results are a bounded page — total, current page and last page are reported', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    foreach (range(1, 3) as $n) {
        seedSearchPatient((string) $tenant->id, ['patient_number' => sprintf('PAT-0000400%d', $n), 'family_name' => 'Paged']);
    }

    $page2 = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'paged', page: 2, perPage: 2));
    expect($page2->total())->toBe(3)
        ->and($page2->currentPage())->toBe(2)
        ->and($page2->lastPage())->toBe(2)
        ->and($page2->count())->toBe(1);
});

// ── facility scope + audit (ADR-129 E / G) ───────────────────────────

test('facility search only returns patients registered at the active facility', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility] = searchFixture();

    $otherFacilityId = (string) TenantContextScope::run((string) $tenant->id, null, fn () => Facility::factory()
        ->forTenant((string) $tenant->id, (string) $org->id)->create()->id);

    $here = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00005001', 'family_name' => 'Scoped'], false);
    seedRegistration((string) $tenant->id, $here, (string) $facility->id);

    $elsewhere = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00005002', 'family_name' => 'Scoped'], false);
    seedRegistration((string) $tenant->id, $elsewhere, $otherFacilityId);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'scoped'));

    expect(resultIds($results))->toBe([$here]);
});

test('a multi-facility user gets results only for the currently active facility', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] = searchFixture();
    $tenantId = (string) $tenant->id;
    $activeFacilityId = (string) $facility->id;
    $otherFacilityId = (string) TenantContextScope::run($tenantId, null, fn () => Facility::factory()
        ->forTenant($tenantId, (string) $org->id)->create()->id);

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $user, $otherFacilityId): void {
        $roleId = DB::table('idn_access_assignments')->where('user_id', $user->id)->value('role_id');
        DB::table('idn_access_assignments')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'user_id' => (string) $user->id,
            'role_id' => $roleId,
            'facility_id' => $otherFacilityId,
            'status' => 'ACTIVE',
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $local = seedSearchPatient($tenantId, ['patient_number' => 'PAT-00005011', 'family_name' => 'Multi']);
    $other = seedSearchPatient($tenantId, ['patient_number' => 'PAT-00005012', 'family_name' => 'Multi'], false);
    seedRegistration($tenantId, $other, $otherFacilityId);

    $atOriginalFacility = dispatchSearch($tenantId, $activeFacilityId, new SearchPatients(nameFragment: 'multi'));
    $atOtherFacility = dispatchSearch($tenantId, $otherFacilityId, new SearchPatients(nameFragment: 'multi'));

    expect(resultIds($atOriginalFacility))->toBe([$local])
        ->and(resultIds($atOtherFacility))->toBe([$other]);
});

test('a search for a patient registered only at another facility returns no result', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility] = searchFixture();

    $otherFacilityId = (string) TenantContextScope::run((string) $tenant->id, null, fn () => Facility::factory()
        ->forTenant((string) $tenant->id, (string) $org->id)->create()->id);

    $elsewhere = seedSearchPatient((string) $tenant->id, ['family_name' => 'Remote', 'primary_phone' => '0766111222'], false);
    seedRegistration((string) $tenant->id, $elsewhere, $otherFacilityId);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(phone: '0766111222'));

    expect($results->total())->toBe(0)
        ->and(searchedAuditRows((string) $tenant->id))->toHaveCount(0);
});

test('a name-fragment-only query is always audited; a tenant-wide caller records cross_facility_lookup=false', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = searchFixture();
    makeCallerTenantWide((string) $tenant->id, (string) $user->id);

    seedSearchPatient((string) $tenant->id, ['family_name' => 'Broadname']);

    dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'broadname'));

    $rows = searchedAuditRows((string) $tenant->id);
    expect($rows)->toHaveCount(1);
    $meta = json_decode((string) $rows->first()->metadata, true);
    expect($meta['criterion_kinds'])->toBe(['name_fragment'])
        ->and($meta['cross_facility_lookup'])->toBeFalse()
        ->and($rows->first()->actor_id)->toBe((string) $user->id);
});

test('a targeted exact search by a tenant-wide caller is NOT audited', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = searchFixture();
    makeCallerTenantWide((string) $tenant->id, (string) $user->id);

    seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00006001', 'family_name' => 'Quiet']);

    dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(patientNumber: 'PAT-00006001'));

    expect(searchedAuditRows((string) $tenant->id))->toHaveCount(0);
});

test('a targeted exact search by a facility-limited caller with an in-scope result is NOT audited', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    $id = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00006002', 'family_name' => 'Local']);

    dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(patientNumber: 'PAT-00006002'));

    expect(searchedAuditRows((string) $tenant->id))->toHaveCount(0);
});

// ── MERGED patients (ADR-129 H) ──────────────────────────────────────

test('a MERGED patient is returned, flagged, and resolves to its survivor', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    $survivor = seedSearchPatient((string) $tenant->id, ['patient_number' => 'PAT-00007001', 'family_name' => 'Survivor']);
    $merged = seedSearchPatient((string) $tenant->id, [
        'patient_number' => 'PAT-00007002', 'family_name' => 'Merged', 'status' => PatientStatus::Merged,
    ]);

    TenantContextScope::run((string) $tenant->id, null, fn () => PatientIdentityHistory::factory()
        ->forTenant((string) $tenant->id)->create([
            'surviving_patient_id' => $survivor,
            'non_surviving_patient_id' => $merged,
            'non_surviving_patient_number' => 'PAT-00007002',
            'status' => MergeHistoryStatus::Active,
        ]));

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(patientNumber: 'PAT-00007002'));

    expect($results->total())->toBe(1);
    $row = $results->items()[0];
    expect($row->patientId)->toBe($merged)
        ->and($row->status)->toBe(PatientStatus::Merged->value)
        ->and($row->survivingPatientId)->toBe($survivor);
});

test('a MERGED patient with no ACTIVE identity-history row has a null survivingPatientId', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    $merged = seedSearchPatient((string) $tenant->id, [
        'patient_number' => 'PAT-00007003', 'family_name' => 'Orphan', 'status' => PatientStatus::Merged,
    ]);

    TenantContextScope::run((string) $tenant->id, null, fn () => PatientIdentityHistory::factory()
        ->forTenant((string) $tenant->id)->reversed()->create([
            'non_surviving_patient_id' => $merged,
            'non_surviving_patient_number' => 'PAT-00007003',
        ]));

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(patientNumber: 'PAT-00007003'));

    $row = $results->items()[0];
    expect($row->status)->toBe(PatientStatus::Merged->value)
        ->and($row->survivingPatientId)->toBeNull();
});

// ── PHI-minimal result contract (ADR-129 A) ──────────────────────────

test('a result row carries only the eight ADR-129 fields — no DOB / phone / email / identifier', function () {
    ['tenant' => $tenant, 'facility' => $facility] = searchFixture();

    seedSearchPatient((string) $tenant->id, [
        'family_name' => 'Minimal', 'display_name' => 'Ada Minimal',
        'date_of_birth' => '1993-03-03', 'primary_phone' => '0722000000', 'email' => 'ada@example.test',
        'sex' => Sex::Female,
    ]);

    $results = dispatchSearch((string) $tenant->id, (string) $facility->id, new SearchPatients(nameFragment: 'minimal'));
    $row = $results->items()[0];

    expect($row)->toBeInstanceOf(PatientSearchResult::class)
        ->and(array_keys(get_object_vars($row)))->toEqualCanonicalizing([
            'patientId', 'patientNumber', 'displayName', 'sex', 'status',
            'isProvisional', 'hasAccessibleFacilityRegistration', 'survivingPatientId',
        ]);

    $encoded = json_encode($row);
    expect($encoded)->not->toContain('1993-03-03')
        ->and($encoded)->not->toContain('0722000000')
        ->and($encoded)->not->toContain('ada@example.test');
});
