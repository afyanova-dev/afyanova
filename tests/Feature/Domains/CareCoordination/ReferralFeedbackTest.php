<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\AcceptReferral;
use App\Domains\CareCoordination\Application\Command\CompleteReferralFeedback;
use App\Domains\CareCoordination\Application\Command\CreateReferral;
use App\Domains\CareCoordination\Application\Command\MarkFeedbackNotProvided;
use App\Domains\CareCoordination\Application\Command\ReceiveReferral;
use App\Domains\CareCoordination\Application\Command\RecordReferralFeedback;
use App\Domains\CareCoordination\Application\Command\SendReferral;
use App\Domains\CareCoordination\Contracts\Event\ReferralFeedbackCompleted;
use App\Domains\CareCoordination\Contracts\Event\ReferralFeedbackNotProvided;
use App\Domains\CareCoordination\Contracts\Event\ReferralFeedbackRecorded;
use App\Domains\CareCoordination\Domain\Exception\IllegalReferralFeedbackTransition;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it records feedback content, transitions to PARTIAL, emits PHI-free ReferralFeedbackRecorded, and logs audit', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    $feedbackId = (string) Str::uuid7();

    // 1. Create and Send from source
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $receivingFacility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
        ));

        app(CommandBus::class)->dispatch(new SendReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    // 2. Receive and Accept at receiving facility (auto-creates feedback EXPECTED)
    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $feedbackId, $user): void {
        app(CommandBus::class)->dispatch(new ReceiveReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));

        app(CommandBus::class)->dispatch(new AcceptReferral(
            referralId: $referralId,
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
        ));
    });

    $feedbackBefore = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => ReferralFeedback::query()->find($feedbackId));
    expect($feedbackBefore->status)->toBe(ReferralFeedback::STATUS_EXPECTED);

    // 3. Record content on feedback thread
    $clinicalSummary = 'Echocardiogram completed: normal LV function, EF 60%';
    $clinicalOutcome = 'DISCHARGED_BACK_TO_PRIMARY';

    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($feedbackId, $user, $clinicalSummary, $clinicalOutcome): void {
        app(CommandBus::class)->dispatch(new RecordReferralFeedback(
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
            kind: 'FINAL',
            summary: $clinicalSummary,
            outcome: $clinicalOutcome,
            receivedVia: 'ELECTRONIC',
        ));
    });

    $feedbackAfter = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => ReferralFeedback::query()->find($feedbackId));
    expect($feedbackAfter->status)->toBe(ReferralFeedback::STATUS_PARTIAL)
        ->and($feedbackAfter->kind)->toBe('FINAL')
        ->and($feedbackAfter->summary)->toBe($clinicalSummary)
        ->and($feedbackAfter->outcome)->toBe($clinicalOutcome)
        ->and($feedbackAfter->received_via)->toBe('ELECTRONIC')
        ->and($feedbackAfter->received_at)->not->toBeNull();

    // Verify ReferralFeedbackRecorded event in outbox
    $event = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralFeedbackRecorded::class)
        ->first());

    expect($event)->not->toBeNull();

    // Verify PHI-free payload (ADR-136 deny-list: no free-text summary or outcome in event payload)
    $payloadJson = (string) $event->payload;
    expect($payloadJson)->not->toContain('Echocardiogram')
        ->and($payloadJson)->not->toContain('DISCHARGED_BACK_TO_PRIMARY');

    $payload = json_decode($payloadJson, true);
    expect($payload['feedbackId'])->toBe($feedbackId)
        ->and($payload['referralId'])->toBe($referralId)
        ->and($payload['kind'])->toBe('FINAL');

    // Verify audit log
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $feedbackId)
        ->where('action', 'care_coordination.referral_feedback_recorded')
        ->first());
    expect($audit)->not->toBeNull()
        ->and(json_decode((string) $audit->metadata, true)['kind'])->toBe('FINAL');
});

test('it completes feedback thread: PARTIAL -> RECEIVED, emits ReferralFeedbackCompleted', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    $feedbackId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $receivingFacility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
        ));

        app(CommandBus::class)->dispatch(new SendReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $feedbackId, $user): void {
        app(CommandBus::class)->dispatch(new ReceiveReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));

        app(CommandBus::class)->dispatch(new AcceptReferral(
            referralId: $referralId,
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
        ));

        app(CommandBus::class)->dispatch(new RecordReferralFeedback(
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
            kind: 'FINAL',
            summary: 'Consultation concluded',
        ));

        app(CommandBus::class)->dispatch(new CompleteReferralFeedback(
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
        ));
    });

    $feedback = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => ReferralFeedback::query()->find($feedbackId));
    expect($feedback->status)->toBe(ReferralFeedback::STATUS_RECEIVED);

    $event = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralFeedbackCompleted::class)
        ->first());
    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true);
    expect($payload['feedbackId'])->toBe($feedbackId);
});

test('it marks feedback as NOT_PROVIDED from EXPECTED, emits ReferralFeedbackNotProvided', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    $feedbackId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $receivingFacility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
        ));

        app(CommandBus::class)->dispatch(new SendReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $feedbackId, $user): void {
        app(CommandBus::class)->dispatch(new ReceiveReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));

        app(CommandBus::class)->dispatch(new AcceptReferral(
            referralId: $referralId,
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
        ));

        // Mark not provided straight from EXPECTED
        app(CommandBus::class)->dispatch(new MarkFeedbackNotProvided(
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
        ));
    });

    $feedback = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => ReferralFeedback::query()->find($feedbackId));
    expect($feedback->status)->toBe(ReferralFeedback::STATUS_NOT_PROVIDED);

    $event = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralFeedbackNotProvided::class)
        ->first());
    expect($event)->not->toBeNull();
});

test('it rejects CompleteReferralFeedback when feedback is still EXPECTED', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    $feedbackId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $receivingFacility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
        ));

        app(CommandBus::class)->dispatch(new SendReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $feedbackId, $user): void {
        app(CommandBus::class)->dispatch(new ReceiveReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));

        app(CommandBus::class)->dispatch(new AcceptReferral(
            referralId: $referralId,
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
        ));
    });

    $command = new CompleteReferralFeedback(
        feedbackId: $feedbackId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(IllegalReferralFeedbackTransition::class);
});

test('it rejects RecordReferralFeedback when user lacks care_coordination.feedback.record permission', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture(permissions: [
        'care_coordination.access',
        'care_coordination.referral.send',
        'care_coordination.referral.receive',
        'encounter.encounters.read',
        'patient.patients.read',
    ]);

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    $feedbackId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $receivingFacility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
        ));

        app(CommandBus::class)->dispatch(new SendReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $feedbackId, $user): void {
        app(CommandBus::class)->dispatch(new ReceiveReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));

        app(CommandBus::class)->dispatch(new AcceptReferral(
            referralId: $referralId,
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
        ));
    });

    $command = new RecordReferralFeedback(
        feedbackId: $feedbackId,
        actorId: (string) $user->id,
        kind: 'FINAL',
        summary: 'Consultation concluded',
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(AuthorizationException::class);
});
