<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\AcceptReferral;
use App\Domains\CareCoordination\Application\Command\CreateReferral;
use App\Domains\CareCoordination\Application\Command\ReceiveReferral;
use App\Domains\CareCoordination\Application\Command\SendReferral;
use App\Domains\CareCoordination\Application\Query\GetPatientReferrals;
use App\Domains\CareCoordination\Application\Query\GetReferral;
use App\Domains\CareCoordination\Application\Query\GetReferralFeedback;
use App\Domains\CareCoordination\Application\Query\GetReferralFeedbackDue;
use App\Domains\CareCoordination\Application\Query\SearchReferrals;
use App\Domains\CareCoordination\Contracts\Dto\ReferralFeedbackSummary;
use App\Domains\CareCoordination\Contracts\Dto\ReferralSummary;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('GetReferral returns ReferralSummary when caller has care_coordination.access', function () {
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
            specialtyRequested: 'NEUROLOGY',
            priority: 'URGENT',
        ));
    });

    /** @var ReferralSummary $summary */
    $summary = TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId) {
        return app(QueryBus::class)->dispatch(new GetReferral($referralId));
    });

    expect($summary)->toBeInstanceOf(ReferralSummary::class)
        ->and($summary->referralId)->toBe($referralId)
        ->and($summary->patientId)->toBe($patientId)
        ->and($summary->facilityId)->toBe((string) $facility->id)
        ->and($summary->targetType)->toBe(Referral::TARGET_TYPE_INTERNAL)
        ->and($summary->targetRef)->toBe((string) $receivingFacility->id)
        ->and($summary->specialtyRequested)->toBe('NEUROLOGY')
        ->and($summary->priority)->toBe('URGENT')
        ->and($summary->status)->toBe('DRAFT');
});

test('SearchReferrals returns inbound referral queue for a facility', function () {
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

    /** @var list<ReferralSummary> $inboundQueue */
    $inboundQueue = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($receivingFacility) {
        return app(QueryBus::class)->dispatch(new SearchReferrals((string) $receivingFacility->id));
    });

    expect($inboundQueue)->toBeArray()
        ->and(count($inboundQueue))->toBeGreaterThanOrEqual(1)
        ->and($inboundQueue[0]->referralId)->toBe($referralId)
        ->and($inboundQueue[0]->status)->toBe(Referral::STATUS_SENT);
});

test('GetPatientReferrals returns all patient referrals tenant-wide', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $ref1 = (string) Str::uuid7();
    $ref2 = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($ref1, $ref2, $user, $patientId, $receivingFacility): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $ref1,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
        ));

        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $ref2,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
        ));
    });

    /** @var list<ReferralSummary> $patientReferrals */
    $patientReferrals = TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($patientId) {
        return app(QueryBus::class)->dispatch(new GetPatientReferrals($patientId));
    });

    expect($patientReferrals)->toBeArray()
        ->and(count($patientReferrals))->toBe(2);
});

test('GetReferralFeedback returns feedback thread for a referral', function () {
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

    /** @var list<ReferralFeedbackSummary> $feedbackList */
    $feedbackList = TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId) {
        return app(QueryBus::class)->dispatch(new GetReferralFeedback($referralId));
    });

    expect($feedbackList)->toBeArray()
        ->and(count($feedbackList))->toBe(1)
        ->and($feedbackList[0]->feedbackId)->toBe($feedbackId)
        ->and($feedbackList[0]->referralId)->toBe($referralId)
        ->and($feedbackList[0]->status)->toBe('EXPECTED')
        ->and($feedbackList[0]->sequence)->toBe(1);
});

test('GetReferralFeedbackDue returns due feedback items for a facility', function () {
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

    /** @var list<ReferralFeedbackSummary> $dueList */
    $dueList = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($receivingFacility) {
        return app(QueryBus::class)->dispatch(new GetReferralFeedbackDue((string) $receivingFacility->id));
    });

    expect($dueList)->toBeArray()
        ->and(count($dueList))->toBeGreaterThanOrEqual(1)
        ->and($dueList[0]->feedbackId)->toBe($feedbackId)
        ->and($dueList[0]->status)->toBe('EXPECTED');
});

test('queries are rejected with AuthorizationException when caller lacks care_coordination.access', function () {
    // User without care_coordination.access
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture(permissions: [
        'care_coordination.referral.send',
        'encounter.encounters.read',
    ]);

    $query = new GetReferral((string) Str::uuid7());

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(QueryBus::class)->dispatch($query)))
        ->toThrow(AuthorizationException::class);
});
