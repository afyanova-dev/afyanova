<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetPrescribingDecision;
use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('GetPrescribingDecision returns PrescribingDecisionSummary for authorized actor at originating facility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $decisionId = clinicalRecordPrescribingDecision(
        (string) $tenant->id,
        (string) $facility->id,
        $patientId,
        $encounterId,
        (string) $user->id,
        ['medicationName' => 'Amoxicillin'],
    );

    /** @var PrescribingDecisionSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetPrescribingDecision($decisionId),
    ));

    expect($summary)->toBeInstanceOf(PrescribingDecisionSummary::class)
        ->and($summary->prescribingDecisionId)->toBe($decisionId)
        ->and($summary->patientId)->toBe($patientId)
        ->and($summary->encounterId)->toBe($encounterId)
        ->and($summary->facilityId)->toBe((string) $facility->id)
        ->and($summary->medicationName)->toBe('Amoxicillin')
        ->and($summary->status)->toBe('ACTIVE')
        // ADR-183 D-N(a): a legacy free-text decision carries a null structured quantity
        ->and($summary->quantity)->toBe('21 capsules')
        ->and($summary->quantityValue)->toBeNull()
        ->and($summary->quantityUnit)->toBeNull();
});

test('GetPrescribingDecision surfaces a prescriber-authored structured quantity (ADR-183 D-H)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $decisionId = clinicalRecordPrescribingDecision(
        (string) $tenant->id,
        (string) $facility->id,
        $patientId,
        $encounterId,
        (string) $user->id,
        ['quantityValue' => '2.5', 'quantityUnit' => 'mL'],
    );

    /** @var PrescribingDecisionSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetPrescribingDecision($decisionId),
    ));

    expect($summary->quantity)->toBeNull()
        ->and($summary->quantityValue)->toBe('2.5000')
        ->and($summary->quantityUnit)->toBe('mL');
});

test('GetPrescribingDecision throws NotFound if actor lacks read permission at originating facility', function () {
    ['tenant' => $tenant, 'facility' => $facility1, 'user' => $user] = planPrescribingFixture();

    $decisionId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) PrescribingDecision::factory()
        ->forTenant((string) $tenant->id)->atFacility((string) Str::uuid7())->create()->id);

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility1->id, fn () => app(QueryBus::class)->dispatch(
        new GetPrescribingDecision($decisionId),
    )))->toThrow(NotFound::class);
});
