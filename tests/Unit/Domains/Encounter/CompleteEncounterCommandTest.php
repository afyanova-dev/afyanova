<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CompleteEncounter;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.7) unit gate — the CompleteEncounter command DTO
 | (IC §3, §5, §18; ADR-121, ADR-130).
 */

test('it is a per-subject command — subjectId is the encounter, ability "complete"', function () {
    $encounterId = (string) Str::uuid7();
    $command = new CompleteEncounter($encounterId, (string) Str::uuid7());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('complete')
        ->and($command->subjectId())->toBe($encounterId);
});

test('it rejects a malformed encounterId / actorId (IC §3)', function () {
    expect(fn () => new CompleteEncounter('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
    expect(fn () => new CompleteEncounter((string) Str::uuid7(), 'nope'))->toThrow(InvalidArgumentException::class);
});

test('auditEntry() targets the encounter with no PHI, no reason and no metadata (R-5)', function () {
    $encounterId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();

    $entry = (new CompleteEncounter($encounterId, $actorId))->auditEntry();

    expect($entry->action)->toBe('encounter.completed')
        ->and($entry->actorId)->toBe($actorId)
        ->and($entry->targetType)->toBe('encounter')
        ->and($entry->targetId)->toBe($encounterId)
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('the command carries exactly encounterId and actorId — no disposition / reason / completedAt / idempotencyKey', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(CompleteEncounter::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['encounterId', 'actorId']);
});

test('the command DTO is final readonly (IC §5)', function () {
    $reflection = new ReflectionClass(CompleteEncounter::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
