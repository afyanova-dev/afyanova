<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\RecordAllergy;
use App\Domains\Clinical\Application\Command\ResolveAllergy;
use App\Domains\Clinical\Application\Command\UpdateAllergy;
use App\Domains\Clinical\Application\Command\VerifyAllergy;
use App\Domains\Clinical\Application\Query\GetAllergy;
use App\Domains\Clinical\Application\Query\GetPatientAllergies;
use App\Domains\Clinical\Contracts\Dto\AllergySummary;
use App\Domains\Clinical\Domain\Exception\EncounterNotEligibleForClinicalRecord;
use App\Domains\Clinical\Domain\ValueObject\AllergyChangeType;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
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
 | PHASE 6 (C.5) cross-domain gate — the canonical Patient -> Encounter ->
 | Allergy composition (blueprint.md; IC §20.2; AGENTS §43). REAL
 | CommandBus / QueryBus / PatientApi / EncounterApi — never mocked (ADR-135).
 | Proves the ADR-141 gates on origination and that update / verify / resolve / read
 | are independent of the encounter's later state.
 */

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/allergy.php';

test('RegisterPatient -> Encounter -> RecordAllergy -> UpdateAllergy(CORRECTION) -> VerifyAllergy(CONFIRMED) -> Complete encounter -> ResolveAllergy (ungated) -> Get', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();

    // 1. Patient
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    // 2. Encounter
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    // 3. RecordAllergy (C.5) consumes real PatientApi + EncounterApi
    $allergyId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new RecordAllergy(
        allergyId: $allergyId,
        actorId: (string) $user->id,
        patientId: $patientId,
        encounterId: $encounterId,
        category: 'DRUG',
        substance: AllergySubstance::of('Penicillin', '764146007', 'SNOMED-CT'),
        reactionDescription: 'Urticaria',
        severity: 'MILD',
        verificationStatus: AllergyVerificationStatus::SUSPECTED,
    )));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($row->tenant_id)->toBe((string) $tenant->id)
        ->and($row->facility_id)->toBe((string) $facility->id)
        ->and($row->patient_id)->toBe($patientId)
        ->and($row->encounter_id)->toBe($encounterId)
        ->and($row->clinical_status)->toBe('ACTIVE')
        ->and($row->verification_status)->toBe('SUSPECTED');

    // 4. UpdateAllergy (CORRECTION) — bounded mutation + history
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new UpdateAllergy(
        allergyId: $allergyId,
        actorId: (string) $user->id,
        changeType: AllergyChangeType::CORRECTION,
        category: 'DRUG',
        substance: AllergySubstance::of('Amoxicillin', '372687004', 'SNOMED-CT'),
        reactionDescription: 'Urticaria and facial edema',
        severity: 'MODERATE',
        reason: 'corrected from penicillin to amoxicillin',
    )));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->count()))->toBe(1);

    // 5. VerifyAllergy (CONFIRMED)
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new VerifyAllergy(
        allergyId: $allergyId,
        actorId: (string) $user->id,
        verificationStatus: AllergyVerificationStatus::CONFIRMED,
    )));
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->count()))->toBe(2);

    // 6. Complete the encounter
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    // 7. ResolveAllergy AFTER encounter completed — ungated, writes no history row
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new ResolveAllergy(
        allergyId: $allergyId,
        actorId: (string) $user->id,
    )));

    $afterResolve = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergies')->where('id', $allergyId)->first());
    expect($afterResolve->clinical_status)->toBe('RESOLVED')
        ->and($afterResolve->resolved_at)->not->toBeNull();

    // Still exactly 2 history rows (from Update and Verify — Resolve writes none)
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_allergy_history')->where('allergy_id', $allergyId)->count()))->toBe(2);

    // 8. Queries
    /** @var AllergySummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetAllergy($allergyId)));
    expect($summary->clinicalStatus)->toBe('RESOLVED')
        ->and($summary->verificationStatus)->toBe('CONFIRMED')
        ->and($summary->substanceName)->toBe('Amoxicillin');

    /** @var list<AllergySummary> $list */
    $list = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(new GetPatientAllergies($patientId)));
    expect($list)->toHaveCount(1)
        ->and($list[0]->allergyId)->toBe($allergyId);
});

test('RecordAllergy against an unknown patient / unknown encounter -> NotFound', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    expect(fn () => clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, (string) Str::uuid7(), $encounterId, (string) $user->id))
        ->toThrow(NotFound::class);

    expect(fn () => clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, (string) Str::uuid7(), (string) $user->id))
        ->toThrow(NotFound::class);
});

test('RecordAllergy against a CANCELLED encounter -> EncounterNotEligibleForClinicalRecord (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = allergyFixture();
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new CancelEncounter($encounterId, (string) $user->id, 'Patient never arrived'));
    });

    expect(fn () => clinicalRecordAllergy((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id))
        ->toThrow(EncounterNotEligibleForClinicalRecord::class);
});
