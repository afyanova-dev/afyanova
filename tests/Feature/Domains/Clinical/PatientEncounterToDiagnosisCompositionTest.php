<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectDiagnosis;
use App\Domains\Clinical\Application\Command\RecordDiagnosis;
use App\Domains\Clinical\Application\Query\GetDiagnosis;
use App\Domains\Clinical\Contracts\Dto\DiagnosisSummary;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
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
 | PHASE 6 (C.3) cross-domain gate — the canonical Patient -> Encounter ->
 | Diagnosis composition (blueprint.md; IC §20.2; AGENTS §43). REAL CommandBus /
 | QueryBus / PatientApi / EncounterApi — never mocked (ADR-135). Proves the
 | ADR-135 gates on origination and that correction / read are independent of
 | the encounter's later state.
 */

uses(RefreshDatabase::class);

test('RegisterPatient -> CreateEncounter -> Start -> RecordDiagnosis -> Get -> Complete encounter -> Correct (ungated) -> Get', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();

    // 1. Patient domain
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('patient_id', $patientId)->count()))->toBe(0);

    // 2. Encounter domain
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    // 3. Clinical diagnosis — RecordDiagnosis consumes the REAL PatientApi + EncounterApi
    $v1 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new RecordDiagnosis(
        diagnosisId: $v1, actorId: (string) $user->id, patientId: $patientId, encounterId: $encounterId,
        coding: DiagnosisCode::of(code: 'B54', codeSystem: 'ICD-10'),
        description: 'Unspecified malaria', clinicalStatus: 'WORKING',
    )));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $v1)->first());
    expect($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)   // from the encounter context
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->code)->toBe('B54');

    /** @var DiagnosisSummary $s */
    $s = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetDiagnosis($v1)));
    expect($s->patientId)->toBe($patientId)->and($s->encounterId)->toBe($encounterId)->and($s->clinicalStatus)->toBe('WORKING');

    // 4. Drive the encounter to COMPLETED
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('COMPLETED');

    // 5. Correct the diagnosis AFTER the encounter completed — ungated
    $v2 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new CorrectDiagnosis(
        newDiagnosisId: $v2, supersedesDiagnosisId: $v1, actorId: (string) $user->id,
        coding: DiagnosisCode::of(code: 'B50.9', codeSystem: 'ICD-10'),
        description: 'Plasmodium falciparum malaria without complication',
        reason: 'species confirmed after the encounter closed',
        clinicalStatus: 'CONFIRMED',
    )));

    $original = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('id', $v1)->first());
    expect($original->code)->toBe('B54')                       // IMMUTABLE
        ->and($original->clinical_status)->toBe('WORKING')
        ->and($original->superseded_by_diagnosis_id)->toBe($v2);

    /** @var DiagnosisSummary $afterCorrection */
    $afterCorrection = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetDiagnosis($v2)));
    expect($afterCorrection->code)->toBe('B50.9')
        ->and($afterCorrection->clinicalStatus)->toBe('CONFIRMED')
        ->and($afterCorrection->supersedesDiagnosisId)->toBe($v1)
        ->and($afterCorrection->correctionReason)->toBe('species confirmed after the encounter closed');

    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->where('patient_id', $patientId)->count()))->toBe(2);
});

// ── cross-domain negative paths (real contract, not mocked) ─────────

test('RecordDiagnosis against an unknown patient / unknown encounter -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
    expect(fn () => clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('RecordDiagnosis against a CANCELLED encounter -> EncounterNotEligibleForClinicalRecord', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new CancelEncounter($encounterId, (string) $user->id));
    });

    expect(fn () => clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_diagnoses')->count()))->toBe(0);
});
