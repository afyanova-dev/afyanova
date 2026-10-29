<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetEncounterProcedures;
use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) gate — GetEncounterProcedures (ADR-146 D-Q): the
 | GetEncounterTriageAssessments originating-facility-refinement shape.
 */

uses(RefreshDatabase::class);

test('returns only the encounter\'s procedures whose originating facility is in the caller\'s scope, deterministically ordered', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $id1 = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    $id2 = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // a procedure at a facility outside the caller's scope, same encounter id (seeded directly)
    TenantContextScope::run((string) $tenant->id, null, fn () => ClinicalProcedure::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->forEncounter($encounterId)->create());

    /** @var list<ProcedureSummary> $result */
    $result = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetEncounterProcedures($encounterId)));

    expect($result)->toHaveCount(2)
        ->and(array_map(fn (ProcedureSummary $s): string => $s->procedureId, $result))->toEqualCanonicalizing([$id1, $id2]);
});

test('a caller without clinical.procedures.read is denied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture([
        'clinical.access', 'patient.patients.read', 'patient.patients.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update',
    ]);
    [, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetEncounterProcedures($encounterId))))
        ->toThrow(AuthorizationException::class);
});
