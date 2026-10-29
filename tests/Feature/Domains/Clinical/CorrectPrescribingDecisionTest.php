<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectPrescribingDecision;
use App\Domains\Clinical\Application\Command\DiscontinuePrescribingDecision;
use App\Domains\Clinical\Contracts\Event\PrescriptionIssued;
use App\Domains\Clinical\Domain\Exception\IllegalPrescribingDecisionStatusTransition;
use App\Domains\Clinical\Domain\Exception\PrescribingDecisionAlreadySuperseded;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../../Support/plan_prescribing.php';

test('CorrectPrescribingDecision creates superseding row, stamps original, emits PrescriptionIssued with CORRECTION', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $originalId = clinicalRecordPrescribingDecision(
        (string) $tenant->id,
        (string) $facility->id,
        $patientId,
        $encounterId,
        (string) $user->id,
        ['dose' => '500 mg'],
    );

    $newId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectPrescribingDecision(
            newPrescribingDecisionId: $newId,
            supersedesPrescribingDecisionId: $originalId,
            actorId: (string) $user->id,
            medication: MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
            reason: 'Dose increased due to lack of response',
            dose: '1000 mg',
        ),
    ));

    $original = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_prescribing_decisions')->where('id', $originalId)->first());
    expect($original->status)->toBe('SUPERSEDED')
        ->and($original->superseded_by_prescribing_decision_id)->toBe($newId)
        ->and($original->superseded_at)->not->toBeNull();

    $newRow = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_prescribing_decisions')->where('id', $newId)->first());
    expect($newRow->status)->toBe('ACTIVE')
        ->and($newRow->supersedes_prescribing_decision_id)->toBe($originalId)
        ->and($newRow->correction_reason)->toBe('Dose increased due to lack of response')
        ->and($newRow->dose)->toBe('1000 mg');

    // RecordPrescribingDecision also emits PrescriptionIssued (for $originalId),
    // so select the correction's event by its own id — not by `latest(created_at)`,
    // which is non-deterministic when the two rows share a microsecond timestamp.
    $event = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PrescriptionIssued::class)
        ->where('payload->prescribingDecisionId', $newId)
        ->first());

    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true);
    expect($payload['prescribingDecisionId'])->toBe($newId)
        ->and($payload['changeType'])->toBe('CORRECTION');
});

test('CorrectPrescribingDecision rejects correcting an already superseded decision (409)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $originalId = clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    $id1 = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectPrescribingDecision(
            newPrescribingDecisionId: $id1,
            supersedesPrescribingDecisionId: $originalId,
            actorId: (string) $user->id,
            medication: MedicationSubstance::of('Amoxicillin'),
            reason: 'Correction 1',
        ),
    ));

    $id2 = (string) Str::uuid7();
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectPrescribingDecision(
            newPrescribingDecisionId: $id2,
            supersedesPrescribingDecisionId: $originalId,
            actorId: (string) $user->id,
            medication: MedicationSubstance::of('Amoxicillin'),
            reason: 'Correction 2',
        ),
    )))->toThrow(PrescribingDecisionAlreadySuperseded::class);
});

test('CorrectPrescribingDecision rejects correcting a DISCONTINUED decision (409, terminal)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $originalId = clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new DiscontinuePrescribingDecision(
            prescribingDecisionId: $originalId,
            actorId: (string) $user->id,
            reason: 'Adverse reaction',
        ),
    ));

    $newId = (string) Str::uuid7();
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectPrescribingDecision(
            newPrescribingDecisionId: $newId,
            supersedesPrescribingDecisionId: $originalId,
            actorId: (string) $user->id,
            medication: MedicationSubstance::of('Amoxicillin'),
            reason: 'Attempted correction after discontinuation',
        ),
    )))->toThrow(IllegalPrescribingDecisionStatusTransition::class);

    // original is untouched — still DISCONTINUED, reason intact, no superseding row
    $original = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_prescribing_decisions')->where('id', $originalId)->first());
    expect($original->status)->toBe('DISCONTINUED')
        ->and($original->discontinued_reason)->toBe('Adverse reaction')
        ->and($original->superseded_by_prescribing_decision_id)->toBeNull();
    expect(Tenancy::run((string) $tenant->id, fn () => DB::table('clin_prescribing_decisions')->where('id', $newId)->exists()))->toBeFalse();
});

test('CorrectPrescribingDecision is NOT encounter-state gated (can correct when encounter closed)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = planPrescribingFixture();
    [$patientId, $encounterId] = clinicalPatientWithEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $originalId = clinicalRecordPrescribingDecision((string) $tenant->id, (string) $facility->id, $patientId, $encounterId, (string) $user->id);

    // Close encounter (e.g. CANCELLED or COMPLETED)
    Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounters')
        ->where('id', $encounterId)
        ->update(['status' => EncounterStatus::Cancelled->value]));

    $newId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(
        new CorrectPrescribingDecision(
            newPrescribingDecisionId: $newId,
            supersedesPrescribingDecisionId: $originalId,
            actorId: (string) $user->id,
            medication: MedicationSubstance::of('Amoxicillin'),
            reason: 'Post-encounter correction',
        ),
    ));

    $newRow = Tenancy::run((string) $tenant->id, fn () => DB::table('clin_prescribing_decisions')->where('id', $newId)->first());
    expect($newRow)->not->toBeNull()
        ->and($newRow->status)->toBe('ACTIVE');
});
