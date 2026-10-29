<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\AbandonEncounter;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.10) unit gate — the AbandonEncounter command DTO
 | (IC §3, §5, §18; ADR-121, ADR-130 A/D).
 */

test('it is a per-subject command — subjectId is the encounter, ability "abandon"', function () {
    $encounterId = (string) Str::uuid7();
    $command = new AbandonEncounter($encounterId, (string) Str::uuid7());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('abandon')
        ->and($command->subjectId())->toBe($encounterId);
});

test('it rejects a malformed encounterId / actorId (IC §3)', function () {
    expect(fn () => new AbandonEncounter('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
    expect(fn () => new AbandonEncounter((string) Str::uuid7(), 'nope'))->toThrow(InvalidArgumentException::class);
});

test('auditEntry() targets the encounter with action encounter.abandoned and empty metadata (ADR-130)', function () {
    $encounterId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();

    $entry = (new AbandonEncounter($encounterId, $actorId))->auditEntry();

    expect($entry->action)->toBe('encounter.abandoned')
        ->and($entry->actorId)->toBe($actorId)
        ->and($entry->targetType)->toBe('encounter')
        ->and($entry->targetId)->toBe($encounterId)
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('the command carries exactly encounterId and actorId — no disposition / reason / evidence / idempotencyKey', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(AbandonEncounter::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['encounterId', 'actorId']);
});

test('the command DTO is final readonly (IC §5)', function () {
    $reflection = new ReflectionClass(AbandonEncounter::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
