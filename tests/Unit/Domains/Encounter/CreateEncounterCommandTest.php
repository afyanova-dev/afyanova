<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\Command\CreateEncounter;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Shared\Audit\Auditable;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

/*
 | PHASE 5 (E.1) unit gate — the CreateEncounter command DTO (IC §3, §5, §18;
 | ADR-130).
 */

/**
 * @param  array<string, mixed>  $overrides
 */
function makeCreateEncounter(array $overrides = []): CreateEncounter
{
    return new CreateEncounter(
        encounterId: $overrides['encounterId'] ?? (string) Str::uuid7(),
        patientId: $overrides['patientId'] ?? (string) Str::uuid7(),
        actorId: $overrides['actorId'] ?? (string) Str::uuid7(),
        encounterType: $overrides['encounterType'] ?? EncounterType::Outpatient,
        planned: $overrides['planned'] ?? false,
        departmentId: $overrides['departmentId'] ?? null,
        unitId: $overrides['unitId'] ?? null,
        source: $overrides['source'] ?? null,
        idempotencyKey: $overrides['idempotencyKey'] ?? null,
    );
}

test('it is a create command — class-level authorization, ability "create"', function () {
    $command = makeCreateEncounter();

    expect($command)->toBeInstanceOf(AuthorizesAgainst::class)
        ->and($command)->toBeInstanceOf(Auditable::class)
        ->and($command->policyAbility())->toBe('create')
        ->and($command->subjectId())->toBeNull();
});

test('it rejects a malformed encounterId / patientId / actorId (IC §3)', function () {
    expect(fn () => makeCreateEncounter(['encounterId' => 'nope']))->toThrow(InvalidArgumentException::class);
    expect(fn () => makeCreateEncounter(['patientId' => 'nope']))->toThrow(InvalidArgumentException::class);
    expect(fn () => makeCreateEncounter(['actorId' => 'nope']))->toThrow(InvalidArgumentException::class);
});

test('it rejects a non-uuid departmentId / unitId but accepts null', function () {
    expect(fn () => makeCreateEncounter(['departmentId' => 'nope']))->toThrow(InvalidArgumentException::class);
    expect(fn () => makeCreateEncounter(['unitId' => 'nope']))->toThrow(InvalidArgumentException::class);
    expect(makeCreateEncounter(['departmentId' => null, 'unitId' => null])->departmentId)->toBeNull();
});

test('it rejects a blank source string', function () {
    expect(fn () => makeCreateEncounter(['source' => '   ']))->toThrow(InvalidArgumentException::class);
    expect(makeCreateEncounter(['source' => null])->source)->toBeNull();
});

test('auditEntry() targets the encounter with PHI-free coded metadata', function () {
    $encounterId = (string) Str::uuid7();
    $patientId = (string) Str::uuid7();
    $actorId = (string) Str::uuid7();

    $entry = makeCreateEncounter([
        'encounterId' => $encounterId,
        'patientId' => $patientId,
        'actorId' => $actorId,
        'encounterType' => EncounterType::Emergency,
        'source' => 'EMERGENCY',
    ])->auditEntry();

    expect($entry->action)->toBe('encounter.created')
        ->and($entry->actorId)->toBe($actorId)
        ->and($entry->targetType)->toBe('encounter')
        ->and($entry->targetId)->toBe($encounterId)
        ->and($entry->metadata)->toEqual([
            'patient_id' => $patientId,
            'encounter_type' => 'EMERGENCY',
            'source' => 'EMERGENCY',
            'planned' => false,
        ]);
});

test('auditEntry() defaults the source to RECEPTION when none is supplied', function () {
    expect(makeCreateEncounter(['source' => null])->auditEntry()->metadata['source'])->toBe('RECEPTION')
        ->and(CreateEncounter::DEFAULT_SOURCE)->toBe('RECEPTION');
});
