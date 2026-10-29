<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\CreateReferral;
use App\Domains\CareCoordination\Domain\Exception\ConsentRequiredForReferral;
use App\Domains\CareCoordination\Domain\Model\Referral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('valid CreateReferral construction succeeds', function () {
    $referralId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $targetRef = (string) Str::uuid7();

    $command = new CreateReferral(
        referralId: $referralId,
        actorId: $actorId,
        patientId: $patientId,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $targetRef,
        direction: Referral::DIRECTION_OUTBOUND,
        priority: 'ROUTINE',
    );

    expect($command->referralId)->toBe($referralId)
        ->and($command->actorId)->toBe($actorId)
        ->and($command->patientId)->toBe($patientId)
        ->and($command->targetType)->toBe(Referral::TARGET_TYPE_INTERNAL)
        ->and($command->targetRef)->toBe($targetRef)
        ->and($command->policyAbility())->toBe('create')
        ->and($command->subjectId())->toBeNull();
});

test('non-uuid fields throw InvalidArgumentException', function () {
    $validUuid = (string) Str::uuid7();

    expect(fn () => new CreateReferral(
        referralId: 'not-a-uuid',
        actorId: $validUuid,
        patientId: $validUuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $validUuid,
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$referralId must be a UUID');

    expect(fn () => new CreateReferral(
        referralId: $validUuid,
        actorId: 'not-a-uuid',
        patientId: $validUuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $validUuid,
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$actorId must be a UUID');

    expect(fn () => new CreateReferral(
        referralId: $validUuid,
        actorId: $validUuid,
        patientId: 'not-a-uuid',
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $validUuid,
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$patientId must be a UUID');

    expect(fn () => new CreateReferral(
        referralId: $validUuid,
        actorId: $validUuid,
        patientId: $validUuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $validUuid,
        sourceEncounterId: 'not-a-uuid',
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$sourceEncounterId must be a UUID');

    expect(fn () => new CreateReferral(
        referralId: $validUuid,
        actorId: $validUuid,
        patientId: $validUuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $validUuid,
        clinicalReasonRef: 'not-a-uuid',
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$clinicalReasonRef must be a UUID');
});

test('invalid direction throws InvalidArgumentException', function () {
    $uuid = (string) Str::uuid7();

    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $uuid,
        direction: 'SIDEWAYS',
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$direction must be OUTBOUND or INBOUND.');
});

test('invalid targetType throws InvalidArgumentException', function () {
    $uuid = (string) Str::uuid7();

    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: 'PLANETARY',
        targetRef: $uuid,
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$targetType must be INTERNAL or EXTERNAL.');
});

test('blank targetRef or priority throws InvalidArgumentException', function () {
    $uuid = (string) Str::uuid7();

    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: '   ',
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$targetRef must not be blank.');

    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $uuid,
        priority: '   ',
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$priority must not be blank.');
});

test('external target requires consentBasisRef', function () {
    $uuid = (string) Str::uuid7();

    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_EXTERNAL,
        targetRef: 'External Clinic Name',
        consentBasisRef: null,
    ))->toThrow(ConsentRequiredForReferral::class);

    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_EXTERNAL,
        targetRef: 'External Clinic Name',
        consentBasisRef: '   ',
    ))->toThrow(ConsentRequiredForReferral::class);

    // If non-uuid passed for consentBasisRef, InvalidArgumentException
    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_EXTERNAL,
        targetRef: 'External Clinic Name',
        consentBasisRef: 'invalid-uuid',
    ))->toThrow(InvalidArgumentException::class, 'CreateReferral::$consentBasisRef must be a UUID');

    // Valid external with UUID consentBasisRef succeeds
    $consentId = (string) Str::uuid7();
    $cmd = new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_EXTERNAL,
        targetRef: 'External Clinic Name',
        consentBasisRef: $consentId,
    );
    expect($cmd->consentBasisRef)->toBe($consentId);
});

test('occurredAt validation handles invalid format, future dates, and backdating threshold', function () {
    $uuid = (string) Str::uuid7();

    // Invalid ISO string
    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $uuid,
        occurredAt: 'not-a-datetime',
    ))->toThrow(InvalidArgumentException::class, 'must be an ISO-8601 datetime');

    // Future date
    $future = CarbonImmutable::now()->addHour()->toIso8601String();
    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $uuid,
        occurredAt: $future,
    ))->toThrow(InvalidArgumentException::class, 'must not be in the future');

    // > 24 hours in past without reason throws
    $past25h = CarbonImmutable::now()->subHours(25)->toIso8601String();
    expect(fn () => new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $uuid,
        occurredAt: $past25h,
        reason: null,
    ))->toThrow(InvalidArgumentException::class, 'is required when $occurredAt is more than 24 hours in the past');

    // > 24 hours in past with reason succeeds
    $cmd = new CreateReferral(
        referralId: $uuid,
        actorId: $uuid,
        patientId: $uuid,
        targetType: Referral::TARGET_TYPE_INTERNAL,
        targetRef: $uuid,
        occurredAt: $past25h,
        reason: 'Delayed paper chart transcription during power cut',
    );
    expect($cmd->reason)->toBe('Delayed paper chart transcription during power cut');
});
