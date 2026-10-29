<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\AcceptReferral;
use App\Domains\CareCoordination\Application\Command\AcknowledgeCareHandover;
use App\Domains\CareCoordination\Application\Command\CreateReferral;
use App\Domains\CareCoordination\Application\Command\ReceiveReferral;
use App\Domains\CareCoordination\Application\Command\SendReferral;
use App\Domains\CareCoordination\Contracts\Event\CareHandoverAcknowledged;
use App\Domains\CareCoordination\Domain\Exception\IllegalReferralTransition;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it acknowledges care handover on ACCEPTED referral with implies_handover true: version bumped, event emitted, audit logged', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    $feedbackId = (string) Str::uuid7();

    // 1. Create with impliesHandover: true and Send
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

    // 2. Receive and Accept at receiving facility
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

    $referralBeforeHandover = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => Referral::query()->find($referralId));
    $versionBefore = (int) $referralBeforeHandover->version;

    // 3. Acknowledge handover at receiving facility
    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $user): void {
        app(CommandBus::class)->dispatch(new AcknowledgeCareHandover(
            referralId: $referralId,
            actorId: (string) $user->id,
        ));
    });

    $referralAfterHandover = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => Referral::query()->find($referralId));
    expect((int) $referralAfterHandover->version)->toBe($versionBefore + 1)
        ->and($referralAfterHandover->updated_at)->not->toBeNull();

    // Verify CareHandoverAcknowledged event in outbox
    $event = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_outbox_events')
        ->where('event_class', CareHandoverAcknowledged::class)
        ->first());

    expect($event)->not->toBeNull();
    $payload = json_decode((string) $event->payload, true);
    expect($payload['referralId'])->toBe($referralId);

    // Verify audit
    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $referralId)
        ->where('action', 'care_coordination.care_handover_acknowledged')
        ->first());
    expect($audit)->not->toBeNull();
});

test('it rejects AcknowledgeCareHandover when implies_handover is false', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    $feedbackId = (string) Str::uuid7();

    // Create referral with impliesHandover: false
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

    $command = new AcknowledgeCareHandover(
        referralId: $referralId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(IllegalReferralTransition::class);
});

test('it rejects AcknowledgeCareHandover when referral is not yet ACCEPTED', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();

    // Create and leave at SENT
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

    $command = new AcknowledgeCareHandover(
        referralId: $referralId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(IllegalReferralTransition::class);
});

test('it rejects AcknowledgeCareHandover when user lacks care_coordination.handover.acknowledge permission at receiving facility', function () {
    // User without care_coordination.handover.acknowledge
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
            impliesHandover: true,
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

    $command = new AcknowledgeCareHandover(
        referralId: $referralId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(AuthorizationException::class);
});
