<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectVitalSignsObservation;
use App\Domains\Clinical\Application\Command\RecordVitalSigns;
use App\Domains\Clinical\Application\Query\GetVitalSignsObservation;
use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
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
 | PHASE 6 (C.2) cross-domain gate — the canonical Patient -> Encounter ->
 | VitalSignsObservation composition (blueprint.md; IC §20.2; AGENTS §43). REAL
 | CommandBus / QueryBus / PatientApi / EncounterApi — never mocked (ADR-135).
 | Proves the ADR-135 gates on origination and that correction / read are
 | independent of the encounter's later state.
 */

uses(RefreshDatabase::class);

test('RegisterPatient -> CreateEncounter -> Start -> RecordVitalSigns -> Get -> Complete encounter -> Correct (ungated) -> Get', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();

    // 1. Patient domain
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('patient_id', $patientId)->count()))->toBe(0);

    // 2. Encounter domain
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    // 3. Clinical vitals — RecordVitalSigns consumes the REAL PatientApi + EncounterApi
    $v1 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new RecordVitalSigns(
        vitalSignsObservationId: $v1, actorId: (string) $user->id, patientId: $patientId, encounterId: $encounterId,
        measurements: VitalSigns::of(temperature: 37.6, heartRate: 88, systolicBp: 118, diastolicBp: 76, oxygenSaturation: 97),
        authoredContext: 'NURSING',
    )));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $v1)->first());
    expect($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)   // from the encounter context
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and((float) $row->temperature)->toBe(37.6);

    /** @var VitalSignsObservationSummary $s */
    $s = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetVitalSignsObservation($v1)));
    expect($s->patientId)->toBe($patientId)->and($s->encounterId)->toBe($encounterId)->and($s->heartRate)->toBe(88);

    // 4. Drive the encounter to COMPLETED
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')->where('id', $encounterId)->value('status')))->toBe('COMPLETED');

    // 5. Correct the observation AFTER the encounter completed — ungated
    $v2 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new CorrectVitalSignsObservation(
        newObservationId: $v2, supersedesObservationId: $v1, actorId: (string) $user->id,
        measurements: VitalSigns::of(temperature: 37.6, heartRate: 88, systolicBp: 128, diastolicBp: 84, oxygenSaturation: 97),
        reason: 'BP re-checked and corrected',
    )));

    // original untouched + superseded; correction current
    $original = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('id', $v1)->first());
    expect((int) $original->systolic_bp)->toBe(118)->and($original->superseded_by_observation_id)->toBe($v2);

    /** @var VitalSignsObservationSummary $afterCorrection */
    $afterCorrection = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetVitalSignsObservation($v2)));
    expect($afterCorrection->systolicBp)->toBe(128)
        ->and($afterCorrection->supersedesObservationId)->toBe($v1)
        ->and($afterCorrection->correctionReason)->toBe('BP re-checked and corrected');

    // exactly two rows (v1 + v2) for this patient
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->where('patient_id', $patientId)->count()))->toBe(2);
});

// ── cross-domain negative paths (real contract, not mocked) ─────────

test('RecordVitalSigns against an unknown patient / unknown encounter -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordVitals((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);
    expect(fn () => clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('RecordVitalSigns against a CANCELLED encounter -> EncounterNotEligibleForClinicalRecord', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = clinicalFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new CancelEncounter($encounterId, (string) $user->id));
    });

    expect(fn () => clinicalRecordVitals((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_vital_sign_observations')->count()))->toBe(0);
});
