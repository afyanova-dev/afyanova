<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetProcedureDefinition;
use App\Domains\Clinical\Application\Query\GetProcedureDefinitions;
use App\Domains\Clinical\Contracts\Dto\ProcedureDefinitionSummary;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) gate — GetProcedureDefinition / GetProcedureDefinitions
 | (ADR-146 D-Q): the catalog is TENANT-WIDE reference data (ADR-146 D-C) — no
 | per-record facility refinement, unlike the ClinicalProcedure instance
 | queries.
 */

uses(RefreshDatabase::class);

test('GetProcedureDefinition returns one catalog entry by id', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $definitionId = clinicalDefineProcedure((string) $tenant->id, ['code' => 'SUTURE-1', 'name' => 'Suturing', 'category' => 'SUTURING']);

    /** @var ProcedureDefinitionSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetProcedureDefinition($definitionId)));

    expect($summary->code)->toBe('SUTURE-1')->and($summary->name)->toBe('Suturing')->and($summary->version)->toBe(1);
});

test('an unknown definition -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility] = clinicalFixture();

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetProcedureDefinition((string) Str::uuid7()))))
        ->toThrow(NotFound::class);
});

test('GetProcedureDefinitions lists the tenant catalog, filterable by category and active', function () {
    ['tenant' => $tenant, 'facility' => $facility] = clinicalFixture();
    clinicalDefineProcedure((string) $tenant->id, ['code' => 'INJ-1', 'name' => 'Injection', 'category' => 'INJECTION']);
    clinicalDefineProcedure((string) $tenant->id, ['code' => 'DRESS-1', 'name' => 'Dressing', 'category' => 'DRESSING_WOUND_CARE']);

    /** @var list<ProcedureDefinitionSummary> $all */
    $all = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetProcedureDefinitions));
    expect($all)->toHaveCount(2);

    /** @var list<ProcedureDefinitionSummary> $injections */
    $injections = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetProcedureDefinitions(category: 'INJECTION')));
    expect($injections)->toHaveCount(1)->and($injections[0]->code)->toBe('INJ-1');
});

test('the catalog is tenant-wide — a definition from tenant A is invisible to tenant B\'s caller', function () {
    // tenant A: define + confirm visible while its own user is the acting principal
    ['tenant' => $tenantA, 'facility' => $facilityA] = clinicalFixture();
    clinicalDefineProcedure((string) $tenantA->id, ['code' => 'ONLY-A']);

    /** @var list<ProcedureDefinitionSummary> $forA */
    $forA = TenantContextScope::run((string) $tenantA->id, (string) $facilityA->id, fn () => app(QueryBus::class)->dispatch(new GetProcedureDefinitions));
    expect($forA)->toHaveCount(1)->and($forA[0]->code)->toBe('ONLY-A');

    // tenant B: a fresh fixture switches the acting principal; sees nothing from A
    ['tenant' => $tenantB, 'facility' => $facilityB] = clinicalFixture();

    /** @var list<ProcedureDefinitionSummary> $forB */
    $forB = TenantContextScope::run((string) $tenantB->id, (string) $facilityB->id, fn () => app(QueryBus::class)->dispatch(new GetProcedureDefinitions));
    expect($forB)->toHaveCount(0);
});
