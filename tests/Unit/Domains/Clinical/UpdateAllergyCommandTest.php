<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\UpdateAllergy;
use App\Domains\Clinical\Domain\ValueObject\AllergyChangeType;
use App\Domains\Clinical\Domain\ValueObject\AllergySubstance;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.5) unit gate — the UpdateAllergy command DTO (IC §3, §5, §18;
 | ADR-132, ADR-141 D-K / D-J).
 */

function updateAllergyArgs(array $overrides = []): array
{
    return array_merge([
        'allergyId' => (string) Str::uuid7(),
        'actorId' => (string) Str::uuid7(),
        'changeType' => AllergyChangeType::REVISION,
        'category' => 'DRUG',
        'substance' => AllergySubstance::of('Penicillin G'),
        'reactionDescription' => 'Urticaria',
        'severity' => 'MILD',
    ], $overrides);
}

test('it is a per-subject command — subjectId = allergyId, ability "update", Auditable + AuthorizesAgainst', function () {
    $c = new UpdateAllergy(...updateAllergyArgs());

    expect($c)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($c)->toBeInstanceOf(Auditable::class)
        ->and($c->policyAbility())->toBe('update')
        ->and($c->subjectId())->toBe($c->allergyId);
});

test('CORRECTION requires a non-blank reason (ADR-141 D-K)', function () {
    expect(fn () => new UpdateAllergy(...updateAllergyArgs([
        'changeType' => AllergyChangeType::CORRECTION,
        'reason' => null,
    ])))->toThrow(InvalidArgumentException::class, 'reason');

    expect(fn () => new UpdateAllergy(...updateAllergyArgs([
        'changeType' => AllergyChangeType::CORRECTION,
        'reason' => '   ',
    ])))->toThrow(InvalidArgumentException::class, 'reason');

    $c = new UpdateAllergy(...updateAllergyArgs([
        'changeType' => AllergyChangeType::CORRECTION,
        'reason' => '  Typo correction  ',
    ]));
    expect($c->correctionReason())->toBe('Typo correction');
});

test('REVISION requires reason to be null or empty and correctionReason() is null', function () {
    expect(fn () => new UpdateAllergy(...updateAllergyArgs([
        'changeType' => AllergyChangeType::REVISION,
        'reason' => 'unexpected reason',
    ])))->toThrow(InvalidArgumentException::class, 'reason');

    $c = new UpdateAllergy(...updateAllergyArgs([
        'changeType' => AllergyChangeType::REVISION,
        'reason' => null,
    ]));
    expect($c->correctionReason())->toBeNull();
});

test('auditEntry() -> clinical.allergies.updated with a NULL reason and empty metadata', function () {
    $c = new UpdateAllergy(...updateAllergyArgs([
        'changeType' => AllergyChangeType::CORRECTION,
        'reason' => 'Clinical correction reason',
    ]));
    $entry = $c->auditEntry();

    expect($entry->action)->toBe('clinical.allergies.updated')
        ->and($entry->actorId)->toBe($c->actorId)
        ->and($entry->targetType)->toBe('allergy')
        ->and($entry->targetId)->toBe($c->allergyId)
        ->and($entry->reason)->toBeNull() // reason is in history ONLY, not audit
        ->and($entry->metadata)->toBe([]);
});

test('it rejects malformed uuids and a blank category', function () {
    expect(fn () => new UpdateAllergy(...updateAllergyArgs(['allergyId' => 'bad'])))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new UpdateAllergy(...updateAllergyArgs(['actorId' => 'bad'])))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new UpdateAllergy(...updateAllergyArgs(['category' => '  '])))
        ->toThrow(InvalidArgumentException::class);
});

test('it rejects a future onset_date', function () {
    expect(fn () => new UpdateAllergy(...updateAllergyArgs(['onsetDate' => CarbonImmutable::now()->addDay()->toDateString()])))
        ->toThrow(InvalidArgumentException::class, 'future');
});

test('the command is final readonly (IC §5)', function () {
    $r = new ReflectionClass(UpdateAllergy::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});
