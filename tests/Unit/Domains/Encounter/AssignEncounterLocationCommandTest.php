<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\AssignEncounterLocation;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.6) unit gate — the AssignEncounterLocation command DTO
 | (IC §3, §5, §18; ADR-121, ADR-130 B).
 */

test('it is a per-subject command — subjectId is the encounter, ability "assignLocation"', function () {
    $encounterId = (string) Str::uuid7();
    $command = new AssignEncounterLocation($encounterId, (string) Str::uuid7(), (string) Str::uuid7(), null);

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('assignLocation')
        ->and($command->subjectId())->toBe($encounterId);
});

test('it rejects a malformed encounterId / actorId / departmentId / unitId (IC §3)', function () {
    $ok = (string) Str::uuid7();

    expect(fn () => new AssignEncounterLocation('nope', $ok, $ok, null))->toThrow(InvalidArgumentException::class);
    expect(fn () => new AssignEncounterLocation($ok, 'nope', $ok, null))->toThrow(InvalidArgumentException::class);
    expect(fn () => new AssignEncounterLocation($ok, $ok, 'nope', null))->toThrow(InvalidArgumentException::class);
    expect(fn () => new AssignEncounterLocation($ok, $ok, null, 'nope'))->toThrow(InvalidArgumentException::class);
});

test('it rejects a both-null construction — there is nothing to assign (ADR-130 B, D-2)', function () {
    $ok = (string) Str::uuid7();

    expect(fn () => new AssignEncounterLocation($ok, $ok, null, null))->toThrow(InvalidArgumentException::class);
});

test('department-only, unit-only and both are all valid constructions', function () {
    $ok = (string) Str::uuid7();

    $deptOnly = new AssignEncounterLocation($ok, $ok, (string) Str::uuid7(), null);
    $unitOnly = new AssignEncounterLocation($ok, $ok, null, (string) Str::uuid7());
    $both = new AssignEncounterLocation($ok, $ok, (string) Str::uuid7(), (string) Str::uuid7());

    expect($deptOnly->unitId)->toBeNull()
        ->and($unitOnly->departmentId)->toBeNull()
        ->and($both->departmentId)->not->toBeNull()
        ->and($both->unitId)->not->toBeNull();
});

test('auditEntry() targets the encounter with no PHI, no reason and no metadata (D-5)', function () {
    $encounterId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();

    $entry = (new AssignEncounterLocation($encounterId, $actorId, (string) Str::uuid7(), null))->auditEntry();

    expect($entry->action)->toBe('encounter.location_changed')
        ->and($entry->actorId)->toBe($actorId)
        ->and($entry->targetType)->toBe('encounter')
        ->and($entry->targetId)->toBe($encounterId)
        ->and($entry->reason)->toBeNull()
        ->and($entry->metadata)->toBe([]);
});

test('the command carries exactly encounterId, actorId, departmentId and unitId', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(AssignEncounterLocation::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['encounterId', 'actorId', 'departmentId', 'unitId']);
});

test('the command DTO is final readonly (IC §5)', function () {
    $reflection = new ReflectionClass(AssignEncounterLocation::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
