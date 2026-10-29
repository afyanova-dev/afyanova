<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectPrescribingDecision;
use App\Domains\Clinical\Application\Command\DiscontinuePrescribingDecision;
use App\Domains\Clinical\Application\Query\GetPrescribingDecision;
use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
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

test('RegisterPatient -> Encounter -> ClinicalPlan -> RecordPrescribingDecision -> Complete encounter -> Correct (ungated) -> Discontinue (ungated) -> Get', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();

    // 1. Patient
    $patientId = clinicalRegisterPatient((string) $tenant->id, (string) $facility->id, (string) $user->id)->patientId;

    // 2. Encounter
    $encounterId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $patientId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new CreateEncounter($encounterId, $patientId, (string) $user->id, EncounterType::Outpatient));
        $bus->dispatch(new StartEncounter($encounterId, (string) $user->id));
    });

    // 3. ClinicalPlan
    $planId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'planType' => ClinicalPlanType::MEDICATION,
        'clinicalIntent' => 'Antibiotic therapy',
    ]);

    // 4. PrescribingDecision linked to ClinicalPlan
    $decisionId = clinicalRecordPrescribingDecision(
        (string) $tenant->id,
        (string) $facility->id,
        $patientId,
        $encounterId,
        (string) $user->id,
        [
            'medication' => MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
            'dose' => '500 mg',
            'clinicalPlanId' => $planId,
        ],
    );

    // 5. Complete Encounter
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user) {
        $bus = app(CommandBus::class);
        $bus->dispatch(new ChangeEncounterDisposition($encounterId, (string) $user->id, EncounterDisposition::Discharged));
        $bus->dispatch(new CompleteEncounter($encounterId, (string) $user->id));
    });

    // 6. CorrectPrescribingDecision (post-origination ungated)
    $correctionId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectPrescribingDecision(
            newPrescribingDecisionId: $correctionId,
            supersedesPrescribingDecisionId: $decisionId,
            actorId: (string) $user->id,
            medication: MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
            reason: 'Switched to 1000 mg after discharge evaluation',
            dose: '1000 mg',
        ),
    ));

    // 7. Discontinue the corrected decision (post-origination ungated)
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new DiscontinuePrescribingDecision(
            prescribingDecisionId: $correctionId,
            actorId: (string) $user->id,
            reason: 'Course completed early due to rapid resolution',
        ),
    ));

    // 8. Query
    /** @var PrescribingDecisionSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetPrescribingDecision($correctionId),
    ));

    expect($summary->status)->toBe('DISCONTINUED')
        ->and($summary->discontinuedReason)->toBe('Course completed early due to rapid resolution')
        ->and($summary->dose)->toBe('1000 mg')
        ->and($summary->clinicalPlanId)->toBe($planId);
});
