<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetProcedure;
use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) gate — GetProcedure (ADR-146 D-Q): #[ProtectedQuery] class-level
 | + per-record originating-facility refinement -> NotFound.
 */

uses(RefreshDatabase::class);

test('returns the full summary including free text, to an authorized caller', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'indication' => 'Infected wound',
    ]);

    /** @var ProcedureSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetProcedure($id)));

    expect($summary->procedureId)->toBe($id)
        ->and($summary->patientId)->toBe($patientId)
        ->and($summary->encounterId)->toBe($encounterId)
        ->and($summary->status)->toBe('REQUESTED')
        ->and($summary->indication)->toBe('Infected wound')
        ->and($summary->amendments)->toBe([]);
});

test('a procedure at a facility outside the caller\'s scope -> NotFound (not a disclosure)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();

    $foreignFacilityId = (string) Str::uuid7();
    $id = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalProcedure::factory()
        ->forTenant((string) $tenant->id)->atFacility($foreignFacilityId)->create()->id);

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetProcedure($id))))
        ->toThrow(NotFound::class);
});

test('GetProcedure rejects a malformed id at construction (IC §3)', function () {
    expect(fn () => new GetProcedure('nope'))->toThrow(InvalidArgumentException::class);
});
