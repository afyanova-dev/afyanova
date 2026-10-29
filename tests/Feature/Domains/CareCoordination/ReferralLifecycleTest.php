<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\AcceptReferral;
use App\Domains\CareCoordination\Application\Command\CancelReferral;
use App\Domains\CareCoordination\Application\Command\CompleteReferral;
use App\Domains\CareCoordination\Application\Command\CreateReferral;
use App\Domains\CareCoordination\Application\Command\ExpireReferral;
use App\Domains\CareCoordination\Application\Command\ReceiveReferral;
use App\Domains\CareCoordination\Application\Command\RejectReferral;
use App\Domains\CareCoordination\Application\Command\SendReferral;
use App\Domains\CareCoordination\Contracts\Event\CareHandoverInitiated;
use App\Domains\CareCoordination\Contracts\Event\ReferralAccepted;
use App\Domains\CareCoordination\Contracts\Event\ReferralCancelled;
use App\Domains\CareCoordination\Contracts\Event\ReferralCompleted;
use App\Domains\CareCoordination\Contracts\Event\ReferralExpired;
use App\Domains\CareCoordination\Contracts\Event\ReferralReceived;
use App\Domains\CareCoordination\Contracts\Event\ReferralRejected;
use App\Domains\CareCoordination\Contracts\Event\ReferralSent;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('SendReferral transitions DRAFT to SENT, emits ReferralSent and CareHandoverInitiated when implies_handover is true', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $receivingFacility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
            impliesHandover: true,
        ));

        app(CommandBus::class)->dispatch(new SendReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    $referral = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Referral::query()->find($referralId));

    expect($referral)->not->toBeNull()
        ->and($referral->status)->toBe(Referral::STATUS_SENT)
        ->and((int) $referral->version)->toBe(1);

    // Verify ReferralSent event in shared_outbox_events
    $sentEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralSent::class)
        ->first());
    expect($sentEvent)->not->toBeNull();
    $sentPayload = json_decode((string) $sentEvent->payload, true);
    expect($sentPayload['referralId'])->toBe($referralId);

    // Verify CareHandoverInitiated event in shared_outbox_events
    $handoverEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', CareHandoverInitiated::class)
        ->first());
    expect($handoverEvent)->not->toBeNull();
    $handoverPayload = json_decode((string) $handoverEvent->payload, true);
    expect($handoverPayload['referralId'])->toBe($referralId);

    // Verify audit
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $referralId)
        ->where('action', 'care_coordination.referral_sent')
        ->first());
    expect($audit)->not->toBeNull();
});

test('SendReferral does not emit CareHandoverInitiated when implies_handover is false', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $receivingFacility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
            impliesHandover: false,
        ));

        app(CommandBus::class)->dispatch(new SendReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    $handoverEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', CareHandoverInitiated::class)
        ->first());
    expect($handoverEvent)->toBeNull();
});

test('full receiving lifecycle: ReceiveReferral -> AcceptReferral (auto-creates EXPECTED feedback) -> CompleteReferral', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    $feedbackId = (string) Str::uuid7();

    // 1. Create and Send from source facility
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $receivingFacility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
            impliesHandover: false,
        ));

        app(CommandBus::class)->dispatch(new SendReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    // 2. Receive at receiving facility
    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $user): void {
        app(CommandBus::class)->dispatch(new ReceiveReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    $referralAfterReceive = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => Referral::query()->find($referralId));
    expect($referralAfterReceive->status)->toBe(Referral::STATUS_RECEIVED);

    $receivedEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralReceived::class)
        ->first());
    expect($receivedEvent)->not->toBeNull();
    $receivedPayload = json_decode((string) $receivedEvent->payload, true);
    expect($receivedPayload['referralId'])->toBe($referralId);

    // 3. Accept at receiving facility
    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $feedbackId, $user): void {
        app(CommandBus::class)->dispatch(new AcceptReferral(
            referralId: $referralId,
            feedbackId: $feedbackId,
            actorId: (string) $user->id,
        ));
    });

    $referralAfterAccept = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => Referral::query()->find($referralId));
    expect($referralAfterAccept->status)->toBe(Referral::STATUS_ACCEPTED);

    $acceptedEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralAccepted::class)
        ->first());
    expect($acceptedEvent)->not->toBeNull();
    $acceptedPayload = json_decode((string) $acceptedEvent->payload, true);
    expect($acceptedPayload['referralId'])->toBe($referralId);

    // Verify auto-created ReferralFeedback row (Invariant 7.7)
    $feedback = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => ReferralFeedback::query()->find($feedbackId));
    expect($feedback)->not->toBeNull()
        ->and($feedback->status)->toBe(ReferralFeedback::STATUS_EXPECTED)
        ->and((int) $feedback->sequence)->toBe(1)
        ->and((string) $feedback->referral_id)->toBe($referralId)
        ->and((string) $feedback->facility_id)->toBe((string) $receivingFacility->id);

    // 4. Complete at receiving facility
    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $user): void {
        app(CommandBus::class)->dispatch(new CompleteReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    $referralAfterComplete = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => Referral::query()->find($referralId));
    expect($referralAfterComplete->status)->toBe(Referral::STATUS_COMPLETED);

    $completedEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralCompleted::class)
        ->first());
    expect($completedEvent)->not->toBeNull();
    $completedPayload = json_decode((string) $completedEvent->payload, true);
    expect($completedPayload['referralId'])->toBe($referralId);
});

test('RejectReferral transitions SENT referral to REJECTED and records audit with reason', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();

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

    // Reject from receiving facility
    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $user): void {
        app(CommandBus::class)->dispatch(new RejectReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            reason: 'Specialty clinic at capacity for 4 weeks',
        ));
    });

    $referral = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => Referral::query()->find($referralId));
    expect($referral->status)->toBe(Referral::STATUS_REJECTED);

    $rejectedEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralRejected::class)
        ->first());
    expect($rejectedEvent)->not->toBeNull();
    $rejectedPayload = json_decode((string) $rejectedEvent->payload, true);
    expect($rejectedPayload['referralId'])->toBe($referralId);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $referralId)
        ->where('action', 'care_coordination.referral_rejected')
        ->first());
    expect($audit)->not->toBeNull()
        ->and($audit->reason)->toBe('Specialty clinic at capacity for 4 weeks');
});

test('CancelReferral transitions DRAFT referral to CANCELLED and records audit with reason', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $facility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $facility->id,
        ));

        app(CommandBus::class)->dispatch(new CancelReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            reason: 'Patient condition resolved spontaneously',
        ));
    });

    $referral = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Referral::query()->find($referralId));
    expect($referral->status)->toBe(Referral::STATUS_CANCELLED);

    $cancelledEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralCancelled::class)
        ->first());
    expect($cancelledEvent)->not->toBeNull();
    $cancelledPayload = json_decode((string) $cancelledEvent->payload, true);
    expect($cancelledPayload['referralId'])->toBe($referralId);
});

test('ExpireReferral transitions a SENT referral to EXPIRED and emits ReferralExpired (ADR-151 D-F/D-J, added during the implementation audit)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $facility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $facility->id,
        ));

        app(CommandBus::class)->dispatch(new SendReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    // System-only command — no actor, no facility/tenant context needed for authorization
    // (ExpireReferral implements neither AuthorizesAgainst nor Auditable).
    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new ExpireReferral(
        referralId: $referralId,
    )));

    $referral = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Referral::query()->find($referralId));
    expect($referral->status)->toBe(Referral::STATUS_EXPIRED);

    $expiredEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralExpired::class)
        ->first());
    expect($expiredEvent)->not->toBeNull();
    $expiredPayload = json_decode((string) $expiredEvent->payload, true);
    expect($expiredPayload['referralId'])->toBe($referralId);
});

test('ExpireReferral is a race-safe no-op on an already-resolved referral (the ExpireBedReservation precedent)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $facility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $facility->id,
        ));

        // Cancelled before any sweep runs against it.
        app(CommandBus::class)->dispatch(new CancelReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    $versionBefore = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => (int) Referral::query()->find($referralId)->version);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch(new ExpireReferral(
        referralId: $referralId,
    )));

    $referral = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Referral::query()->find($referralId));
    expect($referral->status)->toBe(Referral::STATUS_CANCELLED) // unchanged, NOT overwritten to EXPIRED
        ->and((int) $referral->version)->toBe($versionBefore); // no-op — no save happened

    $expiredEvent = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ReferralExpired::class)
        ->first());
    expect($expiredEvent)->toBeNull();
});
