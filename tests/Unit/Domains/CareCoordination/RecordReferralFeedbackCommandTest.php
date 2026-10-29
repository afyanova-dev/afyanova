<?php

declare(strict_types=1);

use App\Domains\CareCoordination\Application\Command\RecordReferralFeedback;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('valid RecordReferralFeedback construction succeeds', function () {
    $feedbackId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();
    $now = CarbonImmutable::now()->toIso8601String();

    $command = new RecordReferralFeedback(
        feedbackId: $feedbackId,
        actorId: $actorId,
        kind: 'ACKNOWLEDGEMENT',
        summary: 'Specialist appointment booked for next Tuesday',
        outcome: 'PENDING_CONSULTATION',
        receivedVia: 'ELECTRONIC',
        receivedAt: $now,
    );

    expect($command->feedbackId)->toBe($feedbackId)
        ->and($command->actorId)->toBe($actorId)
        ->and($command->kind)->toBe('ACKNOWLEDGEMENT')
        ->and($command->summary)->toBe('Specialist appointment booked for next Tuesday')
        ->and($command->outcome)->toBe('PENDING_CONSULTATION')
        ->and($command->receivedVia)->toBe('ELECTRONIC')
        ->and($command->receivedAt)->toBe($now)
        ->and($command->policyAbility())->toBe('record')
        ->and($command->subjectId())->toBe($feedbackId);

    $audit = $command->auditEntry();
    expect($audit->action)->toBe('care_coordination.referral_feedback_recorded')
        ->and($audit->actorId)->toBe($actorId)
        ->and($audit->targetType)->toBe('referral_feedback')
        ->and($audit->targetId)->toBe($feedbackId)
        ->and($audit->metadata['kind'])->toBe('ACKNOWLEDGEMENT')
        // Ensure summary and outcome are not leaked into audit metadata (ADR-136 deny-list)
        ->and(array_key_exists('summary', $audit->metadata))->toBeFalse()
        ->and(array_key_exists('outcome', $audit->metadata))->toBeFalse();
});

test('non-uuid fields throw InvalidArgumentException', function () {
    $validUuid = (string) Str::uuid7();

    expect(fn () => new RecordReferralFeedback(
        feedbackId: 'not-a-uuid',
        actorId: $validUuid,
        kind: 'ACKNOWLEDGEMENT',
    ))->toThrow(InvalidArgumentException::class, 'RecordReferralFeedback::$feedbackId must be a UUID');

    expect(fn () => new RecordReferralFeedback(
        feedbackId: $validUuid,
        actorId: 'not-a-uuid',
        kind: 'ACKNOWLEDGEMENT',
    ))->toThrow(InvalidArgumentException::class, 'RecordReferralFeedback::$actorId must be a UUID');
});

test('blank kind throws InvalidArgumentException', function () {
    $validUuid = (string) Str::uuid7();

    expect(fn () => new RecordReferralFeedback(
        feedbackId: $validUuid,
        actorId: $validUuid,
        kind: '   ',
    ))->toThrow(InvalidArgumentException::class, 'RecordReferralFeedback::$kind must not be blank.');
});

test('invalid receivedAt format throws InvalidArgumentException', function () {
    $validUuid = (string) Str::uuid7();

    expect(fn () => new RecordReferralFeedback(
        feedbackId: $validUuid,
        actorId: $validUuid,
        kind: 'ACKNOWLEDGEMENT',
        receivedAt: 'not-a-date',
    ))->toThrow(InvalidArgumentException::class, 'RecordReferralFeedback::$receivedAt must be an ISO-8601 datetime or null.');
});
