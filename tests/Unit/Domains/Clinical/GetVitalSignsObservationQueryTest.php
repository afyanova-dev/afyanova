<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Query\GetVitalSignsObservation;
use App\Domains\Clinical\Contracts\Dto\VitalSignsObservationSummary;
use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

/*
 | PHASE 6 (C.2) unit gate — the GetVitalSignsObservation query DTO + its
 | VitalSignsObservationSummary result shape (IC §6.2; ADR-133, ADR-138 D15).
 */

test('it is #[ProtectedQuery(view, VitalSignsObservation::class)]', function () {
    $attr = (new ReflectionClass(GetVitalSignsObservation::class))->getAttributes(ProtectedQuery::class)[0] ?? null;
    expect($attr)->not->toBeNull();

    $p = $attr->newInstance();
    expect($p->ability)->toBe('view')->and($p->subjectType)->toBe(VitalSignsObservation::class);
});

test('it validates the observation id and is final readonly', function () {
    expect(fn () => new GetVitalSignsObservation('nope'))->toThrow(InvalidArgumentException::class);

    $id = (string) Str::uuid7();
    expect((new GetVitalSignsObservation($id))->vitalSignsObservationId)->toBe($id);

    $r = new ReflectionClass(GetVitalSignsObservation::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue();
});

test('VitalSignsObservationSummary exposes exactly the stored fields + supersession chain — no BMI, no resolved patient/encounter data', function () {
    $params = array_map(
        static fn (ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod(VitalSignsObservationSummary::class, '__construct'))->getParameters(),
    );

    expect($params)->toBe([
        'vitalSignsObservationId', 'patientId', 'encounterId', 'facilityId',
        'authorUserId', 'authoredContext',
        'temperature', 'heartRate', 'respiratoryRate', 'systolicBp', 'diastolicBp',
        'oxygenSaturation', 'weight', 'height', 'painScore',
        'occurredAt', 'recordedAt',
        'supersedesObservationId', 'supersededAt', 'supersededByObservationId', 'correctionReason',
    ]);

    foreach (['bmi', 'patientName', 'displayName', 'dateOfBirth', 'encounterNumber', 'encounterStatus'] as $forbidden) {
        expect($params)->not->toContain($forbidden);
    }

    $r = new ReflectionClass(VitalSignsObservationSummary::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue()->and($r->getAttributes())->toBe([]);
});

test('the patient-list and by-id queries are the only Clinical vitals reads (no latest/trend/encounter-vitals — ADR-138 D15)', function () {
    $queries = array_map(
        static fn (string $f): string => basename($f, '.php'),
        glob(base_path('app/Domains/Clinical/Application/Query/*Vital*.php')) ?: [],
    );

    expect($queries)->toEqualCanonicalizing([
        'GetPatientVitalSigns',
        'GetPatientVitalSignsHandler',
        'GetVitalSignsObservation',
        'GetVitalSignsObservationHandler',
    ]);
});
