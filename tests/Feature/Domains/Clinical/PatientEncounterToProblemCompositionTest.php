<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\AddProblem;
use App\Domains\Clinical\Application\Command\ResolveProblem;
use App\Domains\Clinical\Application\Command\UpdateProblem;
use App\Domains\Clinical\Application\Query\GetPatientProblems;
use App\Domains\Clinical\Application\Query\GetProblem;
use App\Domains\Clinical\Contracts\Dto\ProblemSummary;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\ValueObject\ProblemChangeType;
use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Domains\Clinical\Domain\ValueObject\ProblemStatus;
use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.4) cross-domain gate — the canonical Patient -> Encounter ->
 | Diagnosis -> Problem composition (blueprint.md; IC §20.2; AGENTS §43). REAL
 | CommandBus / QueryBus / PatientApi / EncounterApi / DiagnosisReader — never
 | mocked (ADR-135). Proves the ADR-140 gates on origination and that update /
 | resolve / read are independent of the encounter's later state.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/problem.php';

test('RegisterPatient -> Encounter -> RecordDiagnosis -> AddProblem(sourceDiagnosisId) -> UpdateProblem(CORRECTION) -> Complete encounter -> ResolveProblem (ungated) -> Get', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();

    // 1. Patient
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    // 2. Encounter
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    // 3. Diagnosis (C.3) — consumed read-only as Problem provenance
    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // 4. Problem (C.4) — AddProblem consumes the REAL PatientApi + EncounterApi + DiagnosisReader
    $problemId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new AddProblem(
        problemId: $problemId, actorId: (string) $user->id, patientId: $patientId, encounterId: $encounterId,
        coding: ProblemCode::of(code: 'I10', codeSystem: 'ICD-10'),
        description: 'Essential (primary) hypertension', sourceDiagnosisId: $diagnosisId,
    )));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());
    expect($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)   // ORIGINATING facility, from the encounter context
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)            // ORIGINATING encounter
        ->and($row->status)->toBe('ACTIVE')
        ->and($row->source_diagnosis_id)->toBe($diagnosisId);

    /** @var ProblemSummary $s */
    $s = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetProblem($problemId)));
    expect($s->patientId)->toBe($patientId)->and($s->status)->toBe('ACTIVE')->and($s->sourceDiagnosisId)->toBe($diagnosisId);

    // 5. Correct the problem's coding — bounded mutation + history
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new UpdateProblem(
        problemId: $problemId, actorId: (string) $user->id, changeType: ProblemChangeType::CORRECTION,
        coding: ProblemCode::of(code: 'I11.9', codeSystem: 'ICD-10'),
        description: 'Hypertensive heart disease without heart failure', reason: 'reclassified after review',
    )));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problem_history')->where('problem_id', $problemId)->count()))->toBe(1);

    // 6. Drive the encounter to COMPLETED
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    // 7. Resolve the problem AFTER the encounter completed — ungated
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new ResolveProblem($problemId, (string) $user->id)));

    $afterResolve = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->where('id', $problemId)->first());
    expect($afterResolve->status)->toBe('RESOLVED')
        ->and($afterResolve->resolved_at)->not->toBeNull()
        ->and($afterResolve->code)->toBe('I11.9')                 // the correction stands
        ->and((int) $afterResolve->version)->toBe(2);             // AddProblem(0) -> UpdateProblem(1) -> ResolveProblem(2)

    // 8. Both reads
    /** @var ProblemSummary $get */
    $get = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetProblem($problemId)));
    expect($get->status)->toBe('RESOLVED')->and($get->code)->toBe('I11.9');

    /** @var list<ProblemSummary> $list */
    $list = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetPatientProblems($patientId, ProblemStatus::RESOLVED)));
    expect($list)->toHaveCount(1)->and($list[0]->problemId)->toBe($problemId);

    // the C.3 diagnosis was never mutated
    $diag = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $diagnosisId)->first());
    expect($diag->code)->toBe('B54')->and($diag->superseded_at)->toBeNull()
        ->and(array_key_exists('problem_id', (array) $diag))->toBeFalse();
});

// ── cross-domain negative paths (real contract, not mocked) ─────────

test('AddProblem against an unknown patient / unknown encounter -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))->toThrow(NotFound::class);
    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, (string) Str::uuid7(), (string) $user->id))->toThrow(NotFound::class);
});

test('AddProblem against a CANCELLED encounter -> EncounterNotEligibleForClinicalRecord', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = problemFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new CancelEncounter($encounterId, (string) $user->id));
    });

    expect(fn () => clinicalAddProblem((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_problems')->count()))->toBe(0);
});
