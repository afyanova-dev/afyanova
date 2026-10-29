<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetClinicalPlan;
use App\Domains\Clinical\Contracts\Dto\ClinicalPlanSummary;
use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('GetClinicalPlan returns ClinicalPlanSummary for authorized actor at originating facility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $planId = clinicalCreatePlan((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id, [
        'clinicalIntent' => 'Manage acute bronchitis',
    ]);

    /** @var ClinicalPlanSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetClinicalPlan($planId),
    ));

    expect($summary)->toBeInstanceOf(ClinicalPlanSummary::class)
        ->and($summary->planId)->toBe($planId)
        ->and($summary->patientId)->toBe($patientId)
        ->and($summary->encounterId)->toBe($encounterId)
        ->and($summary->facilityId)->toBe((string) $facility->id)
        ->and($summary->clinicalIntent)->toBe('Manage acute bronchitis')
        ->and($summary->status)->toBe('ACTIVE');
});

test('GetClinicalPlan throws NotFound if actor lacks read permission at originating facility', function () {
    ['tenant' => $tenant, 'facility' => $facility1, 'user' => $user] = planPrescribingFixture();

    $planId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) ClinicalPlan::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->create()->id);

    // Actor at facility1 lacks permissions at the other facility
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility1->id, fn () => app(QueryBus::class)->dispatch(
        new GetClinicalPlan($planId),
    )))->toThrow(NotFound::class);
});
