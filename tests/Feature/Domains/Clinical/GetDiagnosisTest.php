<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectDiagnosis;
use App\Domains\Clinical\Application\Query\GetDiagnosis;
use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;
use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.3) gate — GetDiagnosis through the real QueryBus / #[ProtectedQuery]
 | pipeline (IC §6.1, §6.2; ADR-139 D12). Read-only: no event, no audit, no
 | state change. Not encounter-state-gated.
 */

uses(RefreshDatabase::class);

function dispatchGetDiagnosis(string $tenantId, ?string $facilityId, string $diagnosisId): DiagnosisSummary
{
    /** @var DiagnosisSummary */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetDiagnosis($diagnosisId)));
}

test('returns the ratified DiagnosisSummary for an authorized reader (code + description included)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $d = TenantContextScope::run((string) $tenant->id, null, fn () => Diagnosis::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)
        ->create(['code' => 'E11.9', 'code_system' => 'ICD-10', 'description' => 'Type 2 diabetes mellitus without complications', 'clinical_status' => 'CONFIRMED']));

    $s = dispatchGetDiagnosis((string) $tenant->id, (string) $facility->id, (string) $d->id);

    expect($s)->toBeInstanceOf(DiagnosisSummary::class)
        ->and($s->diagnosisId)->toBe((string) $d->id)
        ->and($s->patientId)->toBe((string) $d->patient_id)
        ->and($s->encounterId)->toBe((string) $d->encounter_id)
        ->and($s->facilityId)->toBe((string) $facility->id)
        ->and($s->authorUserId)->toBe((string) $user->id)
        ->and($s->authoredContext)->toBe('CLINICIAN')
        ->and($s->code)->toBe('E11.9')
        ->and($s->codeSystem)->toBe('ICD-10')
        ->and($s->description)->toBe('Type 2 diabetes mellitus without complications')
        ->and($s->clinicalStatus)->toBe('CONFIRMED')
        ->and($s->occurredAt)->toBeString()
        ->and($s->recordedAt)->toBeString()
        ->and($s->supersedesDiagnosisId)->toBeNull()
        ->and($s->supersededAt)->toBeNull()
        ->and($s->correctionReason)->toBeNull();
});

test('an un-coded diagnosis returns null code / codeSystem', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Diagnosis::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->uncoded()->create()->id);

    $s = dispatchGetDiagnosis((string) $tenant->id, (string) $facility->id, $id);
    expect($s->code)->toBeNull()->and($s->codeSystem)->toBeNull()->and($s->description)->not->toBe('');
});

test('a superseded diagnosis and its correction each report their place in the chain', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $v1 = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Diagnosis::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->create()->id);

    $v2 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectDiagnosis(
            newDiagnosisId: $v2, supersedesDiagnosisId: $v1, actorId: (string) $user->id,
            coding: DiagnosisCode::none(), description: 'refined conclusion', reason: 'clarified',
        ),
    ));

    $original = dispatchGetDiagnosis((string) $tenant->id, (string) $facility->id, $v1);
    expect($original->supersededAt)->not->toBeNull()
        ->and($original->supersededByDiagnosisId)->toBe($v2)
        ->and($original->supersedesDiagnosisId)->toBeNull()
        ->and($original->correctionReason)->toBeNull();

    $correction = dispatchGetDiagnosis((string) $tenant->id, (string) $facility->id, $v2);
    expect($correction->supersedesDiagnosisId)->toBe($v1)
        ->and($correction->correctionReason)->toBe('clarified')
        ->and($correction->supersededAt)->toBeNull();
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.diagnoses.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.diagnoses.record',
    ]);
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Diagnosis::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->create()->id);

    expect(fn () => dispatchGetDiagnosis((string) $tenant->id, (string) $facility->id, $id))->toThrow(AuthorizationException::class);
});

test('facility refinement: a reader holding clinical.diagnoses.read at facility A cannot read a diagnosis at facility B -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Diagnosis::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->create()->id);

    expect(fn () => dispatchGetDiagnosis((string) $tenant->id, (string) $facilityA->id, $id))->toThrow(NotFound::class);
});

test('a missing / cross-tenant diagnosis -> NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = clinicalFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = clinicalFixture();
    $id = TenantContextScope::run((string) $tenantA->id, null, fn () => (string) Diagnosis::factory()
        ->forTenant((string) $tenantA->id)->atFacility((string) $facilityA->id)->create()->id);

    test()->actingAs($userA);
    expect(fn () => dispatchGetDiagnosis((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7()))->toThrow(NotFound::class);

    test()->actingAs($userB);
    expect(fn () => dispatchGetDiagnosis((string) $tenantB->id, (string) $facilityB->id, $id))->toThrow(NotFound::class);
});

test('the read is side-effect free — no audit, no outbox event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Diagnosis::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->create()->id);

    dispatchGetDiagnosis((string) $tenant->id, (string) $facility->id, $id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('target_id', $id)->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe(0);
});
