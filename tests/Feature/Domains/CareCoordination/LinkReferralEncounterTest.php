<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\AcceptReferral;
use App\Domains\CareCoordination\Application\Command\CreateReferral;
use App\Domains\CareCoordination\Application\Command\LinkReferralEncounter;
use App\Domains\CareCoordination\Application\Command\ReceiveReferral;
use App\Domains\CareCoordination\Application\Command\SendReferral;
use App\Domains\CareCoordination\Domain\Exception\IllegalReferralTransition;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it links receiving encounter to ACCEPTED referral and records reciprocal REFERRED_FROM encounter relationship', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    // Source encounter and patient at originating facility
    ['encounterId' => $sourceEncounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    // Create, Send, Receive, Accept referral
    $referralId = (string) Str::uuid7();
    $feedbackId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($referralId, $user, $patientId, $receivingFacility, $sourceEncounterId): void {
        app(CommandBus::class)->dispatch(new CreateReferral(
            referralId: $referralId,
            actorId: (string) $user->id,
            patientId: $patientId,
            targetType: Referral::TARGET_TYPE_INTERNAL,
            targetRef: (string) $receivingFacility->id,
            sourceEncounterId: $sourceEncounterId,
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

    // Create target encounter for the same patient at the receiving facility
    ['encounterId' => $targetEncounterId] =
        seedEligibleReferralEncounter((string) $tenant->id, (string) $receivingFacility->id, (string) $user->id);

    $relationshipId = (string) Str::uuid7();

    // Dispatch LinkReferralEncounter from receiving facility context
    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $targetEncounterId, $relationshipId, $user): void {
        app(CommandBus::class)->dispatch(new LinkReferralEncounter(
            referralId: $referralId,
            encounterId: $targetEncounterId,
            relationshipId: $relationshipId,
            actorId: (string) $user->id,
        ));
    });

    // Verify referral's target_encounter_id is set
    $referral = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => Referral::query()->find($referralId));
    expect($referral->target_encounter_id)->toBe($targetEncounterId);

    // Verify reciprocal encounter relationship in enc_encounter_relationships
    $relRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounter_relationships')
        ->where('id', $relationshipId)
        ->first());

    expect($relRow)->not->toBeNull()
        ->and($relRow->encounter_id)->toBe($targetEncounterId)
        ->and($relRow->related_encounter_id)->toBe($sourceEncounterId)
        ->and($relRow->relationship_type)->toBe('REFERRED_FROM');
});

test('it links receiving encounter on standalone referral without writing enc_encounter_relationships when source_encounter_id is null', function () {
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
            sourceEncounterId: null, // Standalone referral
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

    ['encounterId' => $targetEncounterId] =
        seedEligibleReferralEncounter((string) $tenant->id, (string) $receivingFacility->id, (string) $user->id);

    $relationshipId = (string) Str::uuid7();

    TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, function () use ($referralId, $targetEncounterId, $relationshipId, $user): void {
        app(CommandBus::class)->dispatch(new LinkReferralEncounter(
            referralId: $referralId,
            encounterId: $targetEncounterId,
            relationshipId: $relationshipId,
            actorId: (string) $user->id,
        ));
    });

    $referral = TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => Referral::query()->find($referralId));
    expect($referral->target_encounter_id)->toBe($targetEncounterId);

    $relRow = Tenancy::run((string) $tenant->id, fn () => DB::table('enc_encounter_relationships')
        ->where('id', $relationshipId)
        ->first());
    expect($relRow)->toBeNull();
});

test('it rejects LinkReferralEncounter when referral is not ACCEPTED', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user, 'role_id' => $roleId] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);
    assignUserToFacility((string) $tenant->id, (string) $user->id, $roleId, (string) $receivingFacility->id);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();

    // Leave referral at SENT (not ACCEPTED)
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

    $targetEncounterId = (string) Str::uuid7();
    $relationshipId = (string) Str::uuid7();

    $command = new LinkReferralEncounter(
        referralId: $referralId,
        encounterId: $targetEncounterId,
        relationshipId: $relationshipId,
        actorId: (string) $user->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $receivingFacility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(IllegalReferralTransition::class);
});
