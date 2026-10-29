<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetTriageAssessment;
use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.9) gate — GetTriageAssessment through the real QueryBus /
 | #[ProtectedQuery] pipeline (IC §6.1, §6.2; ADR-133, ADR-145 D-H). Read-only:
 | no event, no audit, no state change. NOT encounter-state-gated.
 */

uses(RefreshDatabase::class);

function getTriage(string $tenantId, ?string $facilityId, string $id): TriageAssessmentSummary
{
    /** @var TriageAssessmentSummary */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)->dispatch(new GetTriageAssessment($id)));
}

test('returns the ratified TriageAssessmentSummary for an authorized reader (chief complaint + coded priority included)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $a = TenantContextScope::run((string) $tenant->id, null, fn () => TriageAssessment::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)
        ->withPriority('EMERGENCY')->create([
            'chief_complaint' => 'Collapsed, unresponsive', 'acuity' => 'ESI-1', 'consciousness' => 'UNRESPONSIVE',
        ]));

    $s = getTriage((string) $tenant->id, (string) $facility->id, (string) $a->id);

    expect($s)->toBeInstanceOf(TriageAssessmentSummary::class)
        ->and($s->triageAssessmentId)->toBe((string) $a->id)
        ->and($s->facilityId)->toBe((string) $facility->id)
        ->and($s->authorUserId)->toBe((string) $user->id)
        ->and($s->authoredContext)->toBe('NURSING')
        ->and($s->chiefComplaint)->toBe('Collapsed, unresponsive')
        ->and($s->triagePriority)->toBe('EMERGENCY')
        ->and($s->acuity)->toBe('ESI-1')
        ->and($s->consciousness)->toBe('UNRESPONSIVE')
        ->and($s->supersededAt)->toBeNull()
        ->and($s->occurredAt)->toBeString();
});

test('a caller without clinical.triage.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.triage.record',
    ]);
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) TriageAssessment::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->create()->id);

    expect(fn () => getTriage((string) $tenant->id, (string) $facility->id, $id))->toThrow(AuthorizationException::class);
});

test('facility refinement: a reader holding clinical.triage.read at facility A cannot read an assessment at facility B -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) TriageAssessment::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->authoredBy((string) $user->id)->create()->id);

    expect(fn () => getTriage((string) $tenant->id, (string) $facilityA->id, $id))->toThrow(NotFound::class);
});

test('a missing / cross-tenant assessment -> NotFound', function () {
    ['tenant' => $tenantA, 'facility' => $facilityA, 'user' => $userA] = clinicalFixture();
    ['tenant' => $tenantB, 'facility' => $facilityB, 'user' => $userB] = clinicalFixture();
    $id = TenantContextScope::run((string) $tenantA->id, null, fn () => (string) TriageAssessment::factory()
        ->forTenant((string) $tenantA->id)->atFacility((string) $facilityA->id)->authoredBy((string) $userA->id)->create()->id);

    test()->actingAs($userA);
    expect(fn () => getTriage((string) $tenantA->id, (string) $facilityA->id, (string) Str::uuid7()))->toThrow(NotFound::class);
    test()->actingAs($userB);
    expect(fn () => getTriage((string) $tenantB->id, (string) $facilityB->id, $id))->toThrow(NotFound::class);
});

test('the read is side-effect free — no audit, no outbox event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) TriageAssessment::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facility->id)->authoredBy((string) $user->id)->create()->id);

    getTriage((string) $tenant->id, (string) $facility->id, $id);

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')->where('target_id', $id)->count()))->toBe(0)
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')->count()))->toBe(0);
});
