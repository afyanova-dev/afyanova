<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CloseClinicalPlan;
use App\Domains\Clinical\Application\Command\UpdateClinicalPlan;
use App\Domains\Clinical\Application\Query\GetClinicalPlan;
use App\Domains\Clinical\Contracts\Dto\ClinicalPlanSummary;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanChangeType;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
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

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('RegisterPatient -> Encounter -> RecordDiagnosis -> CreateClinicalPlan(indication) -> UpdateClinicalPlan(CORRECTION) -> Complete encounter -> CloseClinicalPlan (ungated) -> GetClinicalPlan', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    grantPermissionsToRole((string) $tenant->id, $user->id, ['clinical.diagnoses.record', 'clinical.diagnoses.read']);

    // 1. Patient
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    // 2. Encounter
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    // 3. Diagnosis (provenance)
    $diagnosisId = clinicalRecordDiagnosis((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // 4. ClinicalPlan
    $planId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'planType' => ClinicalPlanType::MEDICATION,
        'clinicalIntent' => 'Initial medical management',
        'indicationDiagnosisId' => $diagnosisId,
    ]);

    // 5. UpdateClinicalPlan (CORRECTION)
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new UpdateClinicalPlan(
            clinicalPlanId: $planId,
            actorId: (string) $user->id,
            changeType: ClinicalPlanChangeType::CORRECTION,
            planType: ClinicalPlanType::MEDICATION,
            clinicalIntent: 'Refined management based on lab results',
            indicationDiagnosisId: $diagnosisId,
            reason: 'Lab confirmation arrived',
        ),
    ));

    // 6. Complete Encounter
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    // 7. CloseClinicalPlan (ungated post-origination)
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CloseClinicalPlan(
            clinicalPlanId: $planId,
            actorId: (string) $user->id,
            outcome: ClinicalPlanStatus::COMPLETED,
        ),
    ));

    // 8. Query
    /** @var ClinicalPlanSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetClinicalPlan($planId),
    ));

    expect($summary->status)->toBe('COMPLETED')
        ->and($summary->clinicalIntent)->toBe('Refined management based on lab results')
        ->and($summary->indicationDiagnosisId)->toBe($diagnosisId);
});
