<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\VerifyAllergy;
use App\Domains\Clinical\Domain\ValueObject\AllergyVerificationStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) unit gate — the VerifyAllergy command DTO (IC §3, §5, §18;
 | ADR-141 D-K / D-D).
 */

function verifyAllergyArgs(array $overrides = []): array
{
    return array_merge([
        'allergyId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'verificationStatus' => AllergyVerificationStatus::CONFIRMED,
        'reason' => null,
    ], $overrides);
}

test('it is a per-subject command — subjectId = allergyId, ability "verify", Auditable + AuthorizesAgainst', function () {
    $c = new VerifyAllergy(...verifyAllergyArgs());

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('verify')
        ->and($c->subjectId())->toBe($c->allergyId);
});

test('CONFIRMED requires reason to be null or empty and refutationReason() is null', function () {
    expect(fn () => new VerifyAllergy(...verifyAllergyArgs([
        'verificationStatus' => AllergyVerificationStatus::CONFIRMED,
        'reason' => 'some reason',
    ])))->toThrow(InvalidArgumentException::class, 'reason');

    $c = new VerifyAllergy(...verifyAllergyArgs([
        'verificationStatus' => AllergyVerificationStatus::CONFIRMED,
        'reason' => null,
    ]));
    expect($c->refutationReason())->toBeNull();
});

test('REFUTED requires a non-blank reason and refutationReason() returns trimmed text', function () {
    expect(fn () => new VerifyAllergy(...verifyAllergyArgs([
        'verificationStatus' => AllergyVerificationStatus::REFUTED,
        'reason' => null,
    ])))->toThrow(InvalidArgumentException::class, 'reason');

    expect(fn () => new VerifyAllergy(...verifyAllergyArgs([
        'verificationStatus' => AllergyVerificationStatus::REFUTED,
        'reason' => '   ',
    ])))->toThrow(InvalidArgumentException::class, 'reason');

    $c = new VerifyAllergy(...verifyAllergyArgs([
        'verificationStatus' => AllergyVerificationStatus::REFUTED,
        'reason' => '  Skin test negative  ',
    ]));
    expect($c->refutationReason())->toBe('Skin test negative');
});

test('auditEntry() -> clinical.allergies.verified with a NULL reason and empty metadata', function () {
    $c = new VerifyAllergy(...verifyAllergyArgs([
        'verificationStatus' => AllergyVerificationStatus::REFUTED,
        'reason' => 'Negative test result',
    ]));
    $entry = $c->auditEntry();

    expect($entry->action)->toBe('clinical.allergies.verified')
        ->and($entry->actorId)->toBe($c->actorId)
        ->and($entry->targetType)->toBe('allergy')
        ->and($entry->targetId)->toBe($c->allergyId)
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids and invalid verificationStatus', function () {
    expect(fn () => new VerifyAllergy(...verifyAllergyArgs(['allergyId' => 'bad'])))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new VerifyAllergy(...verifyAllergyArgs(['actorId' => 'bad'])))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new VerifyAllergy(...verifyAllergyArgs(['verificationStatus' => 'SUSPECTED'])))
        ->toThrow(InvalidArgumentException::class);
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(VerifyAllergy::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
