<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\CreateReferral;
use App\Domains\CareCoordination\Domain\Exception\EncounterNotEligibleForReferral;
use App\Domains\CareCoordination\Domain\Exception\PatientNotEligibleForReferral;
use App\Domains\CareCoordination\Domain\Exception\ReferralContextMismatch;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('it creates an internal referral with source encounter as DRAFT and writes audit log', function () {
    ['tenant' => $tenant, 'organization' => $org, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    $receivingFacility = createFacilityFixture((string) $tenant->id, (string) $org->id);

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $referralId = (string) Str::uuid7();
    $command = new CreateReferral(
        referralId: $referralId,
        actorId: (string) $user->id,
        patientId: $patientId,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: (string) $receivingFacility->id,
        sourceEncounterId: $encounterId,
        direction: Referral::DIRECTION_OUTBOUND,
        impliesHandover: true,
        specialtyRequested: 'CARDIOLOGY',
        priority: 'URGENT',
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $referral = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Referral::query()->find($referralId));

    expect($referral)->not->toBeNull()
        ->and($referral->status)->toBe(Referral::STATUS_DRAFT)
        ->and($referral->direction)->toBe(Referral::DIRECTION_OUTBOUND)
        ->and((bool) $referral->implies_handover)->toBeTrue()
        ->and((string) $referral->facility_id)->toBe((string) $facility->id)
        ->and((string) $referral->target_type)->toBe(Referral::TARGET_TYPE_INTERNAL)
        ->and((string) $referral->target_ref)->toBe((string) $receivingFacility->id)
        ->and((string) $referral->patient_id)->toBe($patientId)
        ->and((string) $referral->source_encounter_id)->toBe($encounterId)
        ->and($referral->specialty_requested)->toBe('CARDIOLOGY')
        ->and($referral->priority)->toBe('URGENT')
        ->and((int) $referral->version)->toBe(0);

    $audit = Tenancy::run((string) $tenant->id, fn () => DB::table('shared_audit_log')
        ->where('target_id', $referralId)
        ->where('action', 'care_coordination.referral_created')
        ->first());

    expect($audit)->not->toBeNull();
});

test('it creates a standalone external referral without source encounter when consentBasisRef is provided', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $referralId = (string) Str::uuid7();
    $consentId = (string) Str::uuid7();

    $command = new CreateReferral(
        referralId: $referralId,
        actorId: (string) $user->id,
        patientId: $patientId,
        targetType: Referral::TARGET_TYPE_EXTERNAL,
        targetRef: 'National Referral Hospital Cardiology Dept',
        sourceEncounterId: null,
        direction: Referral::DIRECTION_OUTBOUND,
        consentBasisRef: $consentId,
        priority: 'ROUTINE',
    );

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command));

    $referral = TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => Referral::query()->find($referralId));

    expect($referral)->not->toBeNull()
        ->and($referral->status)->toBe(Referral::STATUS_DRAFT)
        ->and($referral->source_encounter_id)->toBeNull()
        ->and((string) $referral->facility_id)->toBe((string) $facility->id)
        ->and((string) $referral->target_type)->toBe(Referral::TARGET_TYPE_EXTERNAL)
        ->and($referral->target_ref)->toBe('National Referral Hospital Cardiology Dept')
        ->and((string) $referral->consent_basis_ref)->toBe($consentId);
});

test('it rejects CreateReferral when patient is INACTIVE, DECEASED, or MERGED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    // 1. INACTIVE
    $inactivePatientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->inactive()
        ->create()->id);

    $cmd1 = new CreateReferral(
        referralId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        patientId: $inactivePatientId,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: (string) $facility->id,
    );
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cmd1)))
        ->toThrow(PatientNotEligibleForReferral::class);

    // 2. DECEASED
    $deceasedPatientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->deceased()
        ->create()->id);

    $cmd2 = new CreateReferral(
        referralId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        patientId: $deceasedPatientId,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: (string) $facility->id,
    );
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cmd2)))
        ->toThrow(PatientNotEligibleForReferral::class);

    // 3. MERGED
    $mergedPatientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->state(['status' => PatientStatus::Merged])
        ->create()->id);

    $cmd3 = new CreateReferral(
        referralId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        patientId: $mergedPatientId,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: (string) $facility->id,
    );
    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($cmd3)))
        ->toThrow(PatientNotEligibleForReferral::class);
});

test('it rejects CreateReferral when source encounter is CANCELLED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    ['encounterId' => $encounterId, 'patientId' => $patientId] =
        seedEligibleReferralEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id, started: false);

    TenantContextScope::run((string) $tenant->id, (string) $facility->id, function () use ($encounterId, $user): void {
        app(CommandBus::class)->dispatch(new CancelEncounter(
            encounterId: $encounterId,
            actorId: (string) $user->id,
        ));
    });

    $command = new CreateReferral(
        referralId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        patientId: $patientId,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: (string) $facility->id,
        sourceEncounterId: $encounterId,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(EncounterNotEligibleForReferral::class);
});

test('it rejects CreateReferral when patientId does not match source encounter patient', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture();

    ['encounterId' => $encounterId] =
        seedEligibleReferralEncounter((string) $tenant->id, (string) $facility->id, (string) $user->id);

    $otherPatientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $command = new CreateReferral(
        referralId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        patientId: $otherPatientId,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: (string) $facility->id,
        sourceEncounterId: $encounterId,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(ReferralContextMismatch::class);
});

test('it rejects CreateReferral when caller lacks care_coordination.referral.send permission', function () {
    // User with only encounter permissions, lacking care_coordination.referral.send
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = careCoordinationFixture(permissions: [
        'care_coordination.access',
        'encounter.encounters.read',
    ]);

    $patientId = TenantContextScope::run((string) $tenant->id, null, fn () => (string) Patient::factory()
        ->forTenant((string) $tenant->id)
        ->create()->id);

    $command = new CreateReferral(
        referralId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        patientId: $patientId,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: (string) $facility->id,
    );

    expect(fn () => TenantContextScope::run((string) $tenant->id, (string) $facility->id, fn () => app(CommandBus::class)->dispatch($command)))
        ->toThrow(AuthorizationException::class);
});
