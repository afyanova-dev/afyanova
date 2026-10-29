<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetProblem;
use App\Domains\Clinical\Contracts\Dto\ProblemSummary;
use App\Domains\Clinical\Domain\Model\Problem;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.4) gate — GetProblem through the real QueryBus / #[ProtectedQuery]
 | pipeline (IC §6.1, §6.2; ADR-140 D-L). Read-only: no event, no audit, no
 | state change. Originating-facility refinement -> NotFound.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/problem.php';

function dispatchGetProblem(string $tenantId, ?string $facilityId, string $problemId): ProblemSummary
{
    /** @var ProblemSummary */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetProblem($problemId)));
}

test('returns the ratified ProblemSummary for an authorized reader (code + description + onset + status included)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $p = TenantContextScope::run((string) $tenant->id, null, fn () => Problem::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)
        ->create(['code' => 'E11.9', 'code_system' => 'ICD-10', 'description' => 'Type 2 diabetes mellitus', 'onset_date' => '2020-01-01']));

    $s = dispatchGetProblem((string) $tenant->id, (string) $facility->id, (string) $p->id);

    expect($s)->toBeInstanceOf(ProblemSummary::class)
        ->and($s->problemId)->toBe((string) $p->id)
        ->and($s->patientId)->toBe((string) $p->patient_id)
        ->and($s->encounterId)->toBe((string) $p->encounter_id)
        ->and($s->facilityId)->toBe((string) $facility->id)
        ->and($s->authorUserId)->toBe((string) $user->id)
        ->and($s->authoredContext)->toBe('CLINICIAN')
        ->and($s->code)->toBe('E11.9')
        ->and($s->codeSystem)->toBe('ICD-10')
        ->and($s->description)->toBe('Type 2 diabetes mellitus')
        ->and($s->status)->toBe('ACTIVE')
        ->and($s->onsetDate)->toBe('2020-01-01')
        ->and($s->resolvedAt)->toBeNull()
        ->and($s->sourceDiagnosisId)->toBeNull()
        ->and($s->occurredAt)->toBeString()
        ->and($s->recordedAt)->toBeString();
});

test('an un-coded / resolved problem reports null coding and a resolvedAt string', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Problem::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->uncoded()->resolved()->create()->id);

    $s = dispatchGetProblem((string) $tenant->id, (string) $facility->id, $id);
    expect($s->code)->toBeNull()->and($s->codeSystem)->toBeNull()
        ->and($s->status)->toBe('RESOLVED')->and($s->resolvedAt)->toBeString();
});

// ── authorization ──────────────────────────────────────────────────

test('a caller without clinical.problems.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture(['clinical.access', 'clinical.problems.record']);
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Problem::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->create()->id);

    expect(fn () => dispatchGetProblem((string) $tenant->id, (string) $facility->id, $id))->toThrow(AuthorizationException::class);
});

test('originating-facility refinement: a reader holding the key at facility A cannot read a problem originated at facility B -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = problemFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Problem::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->create()->id);

    expect(fn () => dispatchGetProblem((string) $tenant->id, (string) $facilityA->id, $id))->toThrow(NotFound::class);
});

test('a missing / cross-tenant problem -> NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = problemFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = problemFixture();
    $id = TenantContextScope::run((string) $tenantA->id, null, fn () => (string) Problem::factory()
        ->forTenant((string) $tenantA->id)->atFacility((string) $facilityA->id)->create()->id);

    test()->actingAs($userA);
    expect(fn () => dispatchGetProblem((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7()))->toThrow(NotFound::class);

    test()->actingAs($userB);
    expect(fn () => dispatchGetProblem((string) $tenantB->id, (string) $facilityB->id, $id))->toThrow(NotFound::class);
});

test('the read is side-effect free — no audit, no outbox event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Problem::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->create()->id);

    dispatchGetProblem((string) $tenant->id, (string) $facility->id, $id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('target_id', $id)->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe(0);
});
