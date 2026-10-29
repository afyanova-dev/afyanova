<?php

declare(strict_types=1);

use App\Domains\Encounter\Application\EncounterApiService;
use App\Domains\Encounter\Contracts\Dto\EncounterContext;
use App\Domains\Encounter\Contracts\EncounterApi;
use App\Domains\Encounter\Domain\EncounterReader;
use App\Shared\Bus\QueryBus;

/*
 | PHASE 5 (E.13) unit gate — the EncounterContext DTO + EncounterApi surface
 | growth (ADR-135). Exactly the five ratified fields; no #[TypeScript].
 */

test('EncounterContext carries exactly encounterId, patientId, facilityId, status, encounterType', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(EncounterContext::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe(['encounterId', 'patientId', 'facilityId', 'status', 'encounterType']);
});

test('EncounterContext is a final readonly DTO with no #[TypeScript] attribute', function () {
    $reflection = new ReflectionClass(EncounterContext::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue()
        ->and($reflection->getAttributes())->toBe([]);
});

test('EncounterApi exposes only methods with current consumers', function () {
    $methods = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        (new ReflectionClass(EncounterApi::class))->getMethods(),
    );

    expect($methods)->toEqualCanonicalizing([
        'activeEncountersForFacility',
        'encounterExists',
        'findActiveEncounterForPatient',
        'getEncounterContext',
        'recordEncounterRelationship',
    ]);

    $return = (new ReflectionMethod(EncounterApi::class, 'getEncounterContext'))->getReturnType();
    expect((string) $return)->toBe(EncounterContext::class);
});

test('EncounterApi gains recordEncounterRelationship() — the first WRITE this port exposes (ADR-151 D-C)', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(EncounterApi::class, 'recordEncounterRelationship'))->getParameters(),
    );

    expect($params)->toBe(['relationshipId', 'encounterId', 'relatedEncounterId', 'relationshipType', 'actorId']);
});

test('EncounterApiService now depends on the QueryBus (delegates to E.12 GetEncounter)', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => (string) $p->getType(),
        (new ReflectionMethod(EncounterApiService::class, '__construct'))->getParameters(),
    );

    expect($params)->toContain(QueryBus::class)
        ->and($params)->toContain(EncounterReader::class);
});
