<?php

declare(strict_types=1);

use App\Domains\Patient\Contracts\Event\PatientIdentifierAdded;
use App\Domains\Patient\Contracts\Event\PatientIdentifierVerified;
use App\Domains\Patient\Domain\Enum\IdentifierType;
use App\Domains\Patient\Domain\Enum\IdentifierVerificationStatus;
use App\Domains\Patient\Domain\Exception\IdentifierNotPendingVerification;
use App\Domains\Patient\Domain\Model\PatientIdentifier;
use Illuminate\Support\Str;

/*
 | 10-patient-domain.md §39-43 / ADR-117 / ADR-122 — the PatientIdentifier
 | aggregate's own invariants, independent of persistence.
 */

/**
 * @param  non-empty-string  $value
 */
function addIdentifier(string $value = 'AB-123 456', IdentifierType $type = IdentifierType::NationalId): PatientIdentifier
{
    $identifier = PatientIdentifier::add(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        type: $type,
        value: $value,
        issuingAuthority: 'NIDA',
        effectiveFrom: null,
        effectiveTo: null,
    );
    $identifier->releaseEvents();

    return $identifier;
}

test('normalize() case-folds and strips every non-alphanumeric character', function () {
    expect(PatientIdentifier::normalize('ab-123 456/x'))->toBe('AB123456X')
        ->and(PatientIdentifier::normalize('  19850101-01234 A  '))->toBe('1985010101234A')
        ->and(PatientIdentifier::normalize('already'))->toBe('ALREADY');
});

test('add() builds an UNVERIFIED identifier, normalizes the value, and records PatientIdentifierAdded', function () {
    $id = (string) Str::uuid7();
    $identifier = PatientIdentifier::add(
        id: $id,
        tenantId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        type: IdentifierType::NationalId,
        value: 'AB-123 456',
        issuingAuthority: 'NIDA',
        effectiveFrom: '2015-01-01',
        effectiveTo: null,
    );

    expect((string) $identifier->id)->toBe($id)
        ->and($identifier->identifier_type)->toBe(IdentifierType::NationalId)
        ->and($identifier->value)->toBe('AB-123 456')
        ->and($identifier->normalized_value)->toBe('AB123456')
        ->and($identifier->verification_status)->toBe(IdentifierVerificationStatus::Unverified)
        ->and((int) $identifier->version)->toBe(0);

    $events = $identifier->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientIdentifierAdded::class);

    /** @var PatientIdentifierAdded $event */
    $event = $events[0];
    expect($event->identifierId)->toBe($id)
        ->and($event->identifierType)->toBe('NATIONAL_ID')
        ->and($event->eventId)->toBeUuid();
});

test('verify() promotes UNVERIFIED -> VERIFIED, stamps the actor, and records PatientIdentifierVerified', function () {
    $identifier = addIdentifier();
    $actorId = (string) Str::uuid7();

    $identifier->verify($actorId, 'NIDA_API');

    expect($identifier->verification_status)->toBe(IdentifierVerificationStatus::Verified)
        ->and($identifier->verified_at)->not->toBeNull()
        ->and($identifier->verified_by_user_id)->toBe($actorId)
        ->and($identifier->verification_source)->toBe('NIDA_API');

    $events = $identifier->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PatientIdentifierVerified::class);
});

test('verify() on an identifier that is not UNVERIFIED is rejected (§42)', function () {
    $identifier = addIdentifier();
    $identifier->verify((string) Str::uuid7(), null);
    $identifier->releaseEvents();

    expect(fn () => $identifier->verify((string) Str::uuid7(), null))
        ->toThrow(IdentifierNotPendingVerification::class);
});

test('IdentifierNotPendingVerification maps to HTTP 409 with a machine code (IC §15)', function () {
    $exception = IdentifierNotPendingVerification::inState(IdentifierVerificationStatus::Revoked);

    expect($exception->httpStatus())->toBe(409)
        ->and($exception->errorCode())->toBe('identifier_not_pending_verification');
});

test('national / passport / insurance types are sensitive; other is not (ADR-122)', function () {
    expect(IdentifierType::NationalId->isSensitive())->toBeTrue()
        ->and(IdentifierType::Passport->isSensitive())->toBeTrue()
        ->and(IdentifierType::InsuranceMember->isSensitive())->toBeTrue()
        ->and(IdentifierType::Other->isSensitive())->toBeFalse();
});

test('neither identifier event contains the raw or normalized value (IC §7.1.1)', function () {
    $identifier = addIdentifier('SECRET-NID-9988', IdentifierType::NationalId);
    $identifier->verify((string) Str::uuid7(), null);

    foreach ($identifier->releaseEvents() as $event) {
        $payload = (array) $event;
        expect($payload)->not->toContain('SECRET-NID-9988')
            ->and($payload)->not->toContain('SECRETNID9988');
    }
});
