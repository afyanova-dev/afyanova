<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\DiscontinuePrescribingDecision;
use App\Domains\Clinical\Domain\Exception\IllegalPrescribingDecisionStatusTransition;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('DiscontinuePrescribingDecision sets status DISCONTINUED, saves reason, emits NO event, writes audit', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $decisionId = clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new DiscontinuePrescribingDecision(
            prescribingDecisionId: $decisionId,
            actorId: (string) $user->id,
            reason: 'Patient experienced severe nausea',
        ),
    ));

    $row = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_prescribing_decisions')->where('id', $decisionId)->first());
    expect($row->status)->toBe('DISCONTINUED')
        ->and($row->discontinued_reason)->toBe('Patient experienced severe nausea');

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('action', 'clinical.prescribing_decisions.discontinued')
        ->where('target_id', $decisionId)
        ->first());
    expect($audit)->not->toBeNull();
});

test('DiscontinuePrescribingDecision rejects non-active decision (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $decisionId = clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new DiscontinuePrescribingDecision(
            prescribingDecisionId: $decisionId,
            actorId: (string) $user->id,
            reason: 'First stop',
        ),
    ));

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new DiscontinuePrescribingDecision(
            prescribingDecisionId: $decisionId,
            actorId: (string) $user->id,
            reason: 'Second stop',
        ),
    )))->toThrow(IllegalPrescribingDecisionStatusTransition::class);
});
