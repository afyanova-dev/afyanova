<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\ReopenEncounter;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.8) unit gate — the ReopenEncounter command DTO
 | (IC §3, §5, §18; ADR-121, ADR-130 C, Rulings E8-R1..E8-R8).
 */

test('it is a per-subject command — subjectId is the encounter, ability "reopen"', function () {
    $encounterId = (string) Str::uuid7();
    $command = new ReopenEncounter($encounterId, (string) Str::uuid7());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('reopen')
        ->and($command->subjectId())->toBe($encounterId);
});

test('it rejects a malformed encounterId / actorId (IC §3)', function () {
    expect(fn () => new ReopenEncounter('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
    expect(fn () => new ReopenEncounter((string) Str::uuid7(), 'nope'))->toThrow(InvalidArgumentException::class);
});

test('auditEntry() targets the encounter with action encounter.reopened and empty metadata (Ruling E8-R6)', function () {
    $encounterId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();

    $entry = (new ReopenEncounter($encounterId, $actorId))->auditEntry();

    expect($entry->action)->toBe('encounter.reopened')
        ->and($entry->actorId)->toBe($actorId)
        ->and($entry->targetType)->toBe('encounter')
        ->and($entry->targetId)->toBe($encounterId)
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('the command carries exactly encounterId and actorId — no disposition / reason / evidence / idempotencyKey', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(ReopenEncounter::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['encounterId', 'actorId']);
});

test('the command DTO is final readonly (IC §5)', function () {
    $reflection = new ReflectionClass(ReopenEncounter::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
