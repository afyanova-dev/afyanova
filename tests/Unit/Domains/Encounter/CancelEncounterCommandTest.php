<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CancelEncounter;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.9) unit gate — the CancelEncounter command DTO
 | (IC §3, §5, §18; ADR-121, ADR-130 A/D).
 */

test('it is a per-subject command — subjectId is the encounter, ability "cancel"', function () {
    $encounterId = (string) Str::uuid7();
    $command = new CancelEncounter($encounterId, (string) Str::uuid7());

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('cancel')
        ->and($command->subjectId())->toBe($encounterId);
});

test('it rejects a malformed encounterId / actorId (IC §3)', function () {
    expect(fn () => new CancelEncounter('nope', (string) Str::uuid7()))->toThrow(InvalidArgumentException::class);
    expect(fn () => new CancelEncounter((string) Str::uuid7(), 'nope'))->toThrow(InvalidArgumentException::class);
});

test('auditEntry() targets the encounter with action encounter.cancelled and empty metadata (ADR-130)', function () {
    $encounterId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();

    $entry = (new CancelEncounter($encounterId, $actorId))->auditEntry();

    expect($entry->action)->toBe('encounter.cancelled')
        ->and($entry->actorId)->toBe($actorId)
        ->and($entry->targetType)->toBe('encounter')
        ->and($entry->targetId)->toBe($encounterId)
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('the command carries exactly encounterId and actorId — no disposition / reason / evidence / idempotencyKey', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(CancelEncounter::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['encounterId', 'actorId']);
});

test('the command DTO is final readonly (IC §5)', function () {
    $reflection = new ReflectionClass(CancelEncounter::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
