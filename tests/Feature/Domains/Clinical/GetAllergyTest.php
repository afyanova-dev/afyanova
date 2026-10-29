<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetAllergy;
use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Clinical\Domain\Model\Allergy;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) gate — GetAllergy (by id) through the real QueryBus /
 | #[ProtectedQuery] pipeline (IC §6.1, §6.2; ADR-141 D-O / D-M). Read-only: no
 | event, no audit, no state change.
 |
 | >>> Q7 (ADR-141 D-M): allergy READS are TENANT-WIDE. Unlike GetProblem /
 |     GetDiagnosis, GetAllergyHandler does NO originating-facility refinement —
 |     an allergy originated at facility B is readable by a caller holding
 |     `clinical.allergies.read` at facility A (same tenant). RLS still confines
 |     the read to the caller's tenant. <<<
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/allergy.php';

function dispatchGetAllergy(string $tenantId, ?string $facilityId, string $allergyId): AllergySummary
{
    /** @var AllergySummary */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetAllergy($allergyId)));
}

// ── the DTO ────────────────────────────────────────────────────────

test('returns the full AllergySummary for an authorized reader (substance name + reaction + severity + both status axes)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $allergy = TenantContextScope::run((string) $tenant->id, null, fn () => Allergy::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)
        ->create([
            'category' => 'DRUG',
            'substance_name' => 'Penicillin',
            'substance_code' => '764146007',
            'substance_code_system' => 'SNOMED-CT',
            'reaction_description' => 'Urticaria and angioedema',
            'severity' => 'SEVERE',
            'clinical_status' => 'ACTIVE',
            'verification_status' => 'CONFIRMED',
            'onset_date' => '2021-06-01',
        ]));

    $s = dispatchGetAllergy((string) $tenant->id, (string) $facility->id, (string) $allergy->id);

    expect($s)->toBeInstanceOf(AllergySummary::class)
        ->and($s->allergyId)->toBe((string) $allergy->id)
        ->and($s->patientId)->toBe((string) $allergy->patient_id)
        ->and($s->encounterId)->toBe((string) $allergy->encounter_id)
        ->and($s->facilityId)->toBe((string) $facility->id)
        ->and($s->authorUserId)->toBe((string) $user->id)
        ->and($s->authoredContext)->toBe('CLINICIAN')
        ->and($s->category)->toBe('DRUG')
        ->and($s->substanceName)->toBe('Penicillin')          // clinical detail IS returned to an authorized reader (D-N)
        ->and($s->substanceCode)->toBe('764146007')
        ->and($s->substanceCodeSystem)->toBe('SNOMED-CT')
        ->and($s->reactionDescription)->toBe('Urticaria and angioedema')
        ->and($s->severity)->toBe('SEVERE')
        ->and($s->clinicalStatus)->toBe('ACTIVE')
        ->and($s->verificationStatus)->toBe('CONFIRMED')
        ->and($s->onsetDate)->toBe('2021-06-01')
        ->and($s->resolvedAt)->toBeNull()
        ->and($s->occurredAt)->toBeString()
        ->and($s->recordedAt)->toBeString();
});

test('an un-coded allergen reports null code / code_system', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Allergy::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->uncoded()
        ->create(['substance_name' => 'Bee sting', 'category' => 'ENVIRONMENT'])->id);

    $s = dispatchGetAllergy((string) $tenant->id, (string) $facility->id, $id);
    expect($s->substanceCode)->toBeNull()->and($s->substanceCodeSystem)->toBeNull()
        ->and($s->substanceName)->toBe('Bee sting');
});

test('a REFUTED allergy reports INACTIVE clinical status (the D-D biconditional)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Allergy::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->refuted()->create()->id);

    $s = dispatchGetAllergy((string) $tenant->id, (string) $facility->id, $id);
    expect($s->verificationStatus)->toBe('REFUTED')->and($s->clinicalStatus)->toBe('INACTIVE');
});

// ── Q7: tenant-wide read visibility (ADR-141 D-M) ──────────────────

test('Q7 tenant-wide read: a reader at facility A CAN read an allergy originated at facility B (same tenant)', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = allergyFixture();
    $facilityB = (string) Str::uuid7();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Allergy::factory()
        ->forTenant((string) $tenant->id)->atFacility($facilityB)
        ->create(['substance_name' => 'Latex', 'category' => 'ENVIRONMENT'])->id);

    // Caller is assigned only at facility A — the allergy is at facility B.
    // GetProblem / GetDiagnosis would throw NotFound here; GetAllergy must NOT.
    $s = dispatchGetAllergy((string) $tenant->id, (string) $facilityA->id, $id);

    expect($s->allergyId)->toBe($id)
        ->and($s->facilityId)->toBe($facilityB)          // provenance is still the originating facility
        ->and($s->substanceName)->toBe('Latex');
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.allergies.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture(['clinical.access', 'clinical.allergies.record']);
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Allergy::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->create()->id);

    expect(fn () => dispatchGetAllergy((string) $tenant->id, (string) $facility->id, $id))->toThrow(AuthorizationException::class);
});

test('a missing / cross-tenant allergy -> NotFound (RLS confines the read to the tenant)', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = allergyFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = allergyFixture();
    $id = TenantContextScope::run((string) $tenantA->id, null, fn () => (string) Allergy::factory()
        ->forTenant((string) $tenantA->id)->atFacility((string) $facilityA->id)->create()->id);

    test()->actingAs($userA);
    expect(fn () => dispatchGetAllergy((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7()))->toThrow(NotFound::class);

    // tenant B cannot see tenant A's allergy even by exact id
    test()->actingAs($userB);
    expect(fn () => dispatchGetAllergy((string) $tenantB->id, (string) $facilityB->id, $id))->toThrow(NotFound::class);
});

// ── read is side-effect free ──────────────────────────────────────

test('the read writes no audit row and no outbox event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Allergy::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->create()->id);

    dispatchGetAllergy((string) $tenant->id, (string) $facility->id, $id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe(0);
});

test('GetAllergy rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new GetAllergy('not-a-uuid'))->toThrow(InvalidArgumentException::class);
});
