<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AuthorizeProcedure;
use App\Domains\Clinical\Application\Command\CompleteProcedure;
use App\Domains\Clinical\Application\Command\RequestProcedure;
use App\Domains\Clinical\Application\Command\StartProcedure;
use App\Domains\Clinical\Application\Query\GetProcedure;
use App\Domains\Clinical\Contracts\Dto\ProcedureSummary;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (CP.1) cross-domain gate — the canonical Patient -> Encounter ->
 | ClinicalProcedure composition (blueprint.md §192; IC §20.2; AGENTS §43). REAL
 | CommandBus / QueryBus / PatientApi / EncounterApi / FacilityCapabilities —
 | never mocked (ADR-135, ADR-146). Proves origination is gated at arrival, and
 | the post-origination lifecycle is independent of the encounter's later state.
 */

uses(RefreshDatabase::class);

test('RegisterPatient -> CreateEncounter -> Start -> RequestProcedure -> Authorize -> Start -> Complete -> Get, even after the encounter completes', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();

    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    $procedureId = clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user): void {
        app(CommandBus::class)->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        app(CommandBus::class)->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('COMPLETED');

    // the post-origination lifecycle proceeds despite the encounter now being COMPLETED (13 §90-91)
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($procedureId, $user): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new AuthorizeProcedure($procedureId, (string) $user->id));
        $bus->dispatch(new StartProcedure($procedureId, (string) $user->id));
        $bus->dispatch(new CompleteProcedure($procedureId, (string) $user->id, 'SUCCESSFUL'));
    });

    /** @var ProcedureSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetProcedure($procedureId)));
    expect($summary->status)->toBe('COMPLETED')->and($summary->outcome)->toBe('SUCCESSFUL');

    // real Patient / Encounter rows, no cross-domain FK
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('pat_patients')->where('id', $patientId)->exists()))->toBeTrue()
        ->and(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->exists()))->toBeTrue();
});

test('RequestProcedure against a PLANNED (not-yet-arrived) encounter is rejected — origination requires attendance (ADR-135)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)
        ->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient, planned: true)));

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('PLANNED');

    expect(fn () => clinicalRequestProcedure((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
});

test('RequestProcedure rejects a malformed id at construction, matching every C.n command (IC §3)', function () {
    expect(fn () => new RequestProcedure(
        procedureId: (string) Str::uuid7(), actorId: (string) Str::uuid7(), patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(), definitionId: 'not-a-uuid',
    ))->toThrow(InvalidArgumentException::class);
});
