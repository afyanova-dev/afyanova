<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\ChangeEncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.5) unit gate — the ChangeEncounterDisposition command DTO
 | (IC §3, §5, §18; ADR-121, ADR-130 A).
 */

test('it is a per-subject command — subjectId is the encounter, ability "changeDisposition"', function () {
    $encounterId = (string) Str::uuid7();
    $command = new ChangeEncounterDisposition($encounterId, (string) Str::uuid7(), EncounterDisposition::Discharged);

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('changeDisposition')
        ->and($command->subjectId())->toBe($encounterId)
        ->and($command->disposition)->toBe(EncounterDisposition::Discharged);
});

test('it rejects a malformed encounterId / actorId (IC §3)', function () {
    expect(fn () => new ChangeEncounterDisposition('nope', (string) Str::uuid7(), EncounterDisposition::Referred))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new ChangeEncounterDisposition((string) Str::uuid7(), 'nope', EncounterDisposition::Referred))
        ->toThrow(InvalidArgumentException::class);
});

test('auditEntry() targets the encounter with no PHI, no reason and no metadata (ADR-130 D-5)', function () {
    $encounterId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();

    $entry = (new ChangeEncounterDisposition($encounterId, $actorId, EncounterDisposition::FollowUp))->auditEntry();

    expect($entry->action)->toBe('encounter.disposition_changed')
        ->and($entry->actorId)->toBe($actorId)
        ->and($entry->targetType)->toBe('encounter')
        ->and($entry->targetId)->toBe($encounterId)
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('the command carries exactly encounterId, actorId and disposition — no reason / evidence / changedAt / facility / tenant / patient / status', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(ChangeEncounterDisposition::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['encounterId', 'actorId', 'disposition']);
});

test('the command DTO is final readonly (IC §5)', function () {
    $reflection = new ReflectionClass(ChangeEncounterDisposition::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
