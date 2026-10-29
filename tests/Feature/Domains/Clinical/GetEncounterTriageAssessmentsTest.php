<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectTriageAssessment;
use App\Domains\Clinical\Application\Query\GetEncounterTriageAssessments;
use App\Domains\Clinical\Contracts\Dto\TriageAssessmentSummary;
use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.9) gate — GetEncounterTriageAssessments: one encounter's triage
 | chain, originating-facility-scoped, deterministic order, ?currentOnly, NO
 | pagination (ADR-145 D-H / D-P).
 */

uses(RefreshDatabase::class);

/**
 * @return list<TriageAssessmentSummary>
 */
function getEncounterTriage(string $tenantId, ?string $facilityId, string $encounterId, bool $currentOnly = false): array
{
    /** @var list<TriageAssessmentSummary> */
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(QueryBus::class)
        ->dispatch(new GetEncounterTriageAssessments($encounterId, $currentOnly)));
}

test('returns the encounter chain ordered by occurred_at; ?currentOnly filters out superseded rows', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $v1 = clinicalRecordTriage((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, ['triagePriority' => 'LOW']);
    $v2 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectTriageAssessment(
            newTriageAssessmentId: $v2, supersedesTriageAssessmentId: $v1, actorId: (string) $user->id,
            chiefComplaint: 'worse', triagePriority: 'HIGH', reason: 'deteriorated',
        ),
    ));

    $all = getEncounterTriage((string) $tenant->id, (string) $facility->id, $encounterId);
    expect($all)->toHaveCount(2)
        ->and($all[0]->triageAssessmentId)->toBe($v1)
        ->and($all[0]->supersededAt)->not->toBeNull()
        ->and($all[1]->supersededAt)->toBeNull()
        ->and($all[1]->triagePriority)->toBe('HIGH');

    $current = getEncounterTriage((string) $tenant->id, (string) $facility->id, $encounterId, true);
    expect($current)->toHaveCount(1)
        ->and($current[0]->supersededAt)->toBeNull()
        ->and($current[0]->triagePriority)->toBe('HIGH');
});

test('an empty result for an encounter with no triage assessments', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(getEncounterTriage((string) $tenant->id, (string) $facility->id, $encounterId))->toBe([]);
});

test('originating-facility scope: assessments recorded at a facility the caller is not assigned to are not returned', function () {
    ['tenant' => $tenant, 'facility' => $facilityA, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facilityA->id, (string) $user->id)->patientId;
    $encounterId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Encounter::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) $facilityA->id)->forPatient($patientId)
        ->withStatus(EncounterStatus::Arrived)->create()->id);

    // seed a triage row for THIS encounter but stamped at a different (out-of-scope) facility via the factory
    TenantContextScope::run((string) $tenant->id, null, fn () => TriageAssessment::factory()
        ->forTenant((string) $tenant->id)->forEncounter($encounterId)->atFacility((string) Str::uuid7())
        ->authoredBy((string) $user->id)->create());

    expect(getEncounterTriage((string) $tenant->id, (string) $facilityA->id, $encounterId))->toBe([]);
});

test('a caller without clinical.triage.read is denied (AuthorizationException)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'clinical.triage.record',
    ]);
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, null, fn () => TriageAssessment::factory()
        ->forTenant((string) $tenant->id)->forEncounter($encounterId)->atFacility((string) $facility->id)
        ->authoredBy((string) $user->id)->create());

    expect(fn () => getEncounterTriage((string) $tenant->id, (string) $facility->id, $encounterId))
        ->toThrow(AuthorizationException::class);
});
