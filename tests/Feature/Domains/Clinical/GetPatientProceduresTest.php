<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetPatientProcedures;
use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) gate — GetPatientProcedures (ADR-146 D-Q): the
 | GetPatientProblems originating-facility-refinement shape — a patient's
 | procedure history across encounters.
 */

uses(RefreshDatabase::class);

test('returns a patient\'s procedures across two encounters, originating-facility-scoped', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterA] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);
    $id1 = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterA, (string) $user->id);

    // close encounter A — a patient may not hold two ACTIVE encounters at once (Inv 12b)
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterA, $user): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterA, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterA, (string) $user->id));
    });

    // a second attendance for the SAME patient
    $encounterB = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterB, $patientId, $user): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterB, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterB, (string) $user->id));
    });
    $id2 = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterB, (string) $user->id);

    /** @var list<ProcedureSummary> $result */
    $result = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetPatientProcedures($patientId)));

    expect($result)->toHaveCount(2)
        ->and(array_map(fn (ProcedureSummary $s): string => $s->procedureId, $result))->toEqualCanonicalizing([$id1, $id2]);
});
