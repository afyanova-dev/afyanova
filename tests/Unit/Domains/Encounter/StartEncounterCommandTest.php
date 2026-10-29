<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\StartEncounter;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.3) unit gate — the StartEncounter command DTO (IC §3, §5, §18;
 | ADR-121, ADR-130).
 */

test('it is a per-subject command — subjectId is the encounter, ability "start"', function () {
    $encounterId = (string) Str::uuid7();
    $command = new StartEncounter($encounterId, (string) Str::uuid7());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('start')
        ->and($command->subjectId())->toBe($encounterId);
});

test('it rejects a malformed encounterId / actorId (IC §3)', function () {
    expect(fn () => new StartEncounter('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
    expect(fn () => new StartEncounter((string) Str::uuid7(), 'nope'))->toThrow(InvalidArgumentException::class);
});

test('auditEntry() targets the encounter with no PHI and no metadata', function () {
    $encounterId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();

    $entry = (new StartEncounter($encounterId, $actorId))->auditEntry();

    expect($entry->action)->toBe('encounter.started')
        ->and($entry->actorId)->toBe($actorId)
        ->and($entry->targetType)->toBe('encounter')
        ->and($entry->targetId)->toBe($encounterId)
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('the command carries exactly encounterId and actorId — no startedAt / reason / idempotencyKey / tenant / facility / patient / status', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(StartEncounter::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['encounterId', 'actorId']);
});
