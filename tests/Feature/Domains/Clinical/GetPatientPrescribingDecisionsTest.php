<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\DiscontinuePrescribingDecision;
use App\Domains\Clinical\Application\Query\GetPatientPrescribingDecisions;
use App\Domains\Clinical\Contracts\Dto\PrescribingDecisionSummary;
use App\Domains\Clinical\Domain\ValueObject\PrescribingDecisionStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('GetPatientPrescribingDecisions returns patient decisions scoped to in-scope facilities and status', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $dec1 = clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);
    $dec2 = clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new DiscontinuePrescribingDecision(
            prescribingDecisionId: $dec2,
            actorId: (string) $user->id,
            reason: 'Stopped',
        ),
    ));

    /** @var list<PrescribingDecisionSummary> $all */
    $all = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetPatientPrescribingDecisions($patientId),
    ));
    expect($all)->toHaveCount(2);

    /** @var list<PrescribingDecisionSummary> $activeOnly */
    $activeOnly = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetPatientPrescribingDecisions($patientId, PrescribingDecisionStatus::ACTIVE),
    ));
    expect($activeOnly)->toHaveCount(1)
        ->and($activeOnly[0]->prescribingDecisionId)->toBe($dec1);

    /** @var list<PrescribingDecisionSummary> $discontinuedOnly */
    $discontinuedOnly = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch(
        new GetPatientPrescribingDecisions($patientId, PrescribingDecisionStatus::DISCONTINUED),
    ));
    expect($discontinuedOnly)->toHaveCount(1)
        ->and($discontinuedOnly[0]->prescribingDecisionId)->toBe($dec2);
});
