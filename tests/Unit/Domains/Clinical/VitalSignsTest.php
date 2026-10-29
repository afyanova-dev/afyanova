<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\VitalSigns;

/*
 | PHASE 6 (C.2) unit gate — the VitalSigns value object (12-clinical-domain.md
 | §19–§20; ADR-138 D2/D3/D4/D5). Fixed canonical units, structural safety
 | bounds only, the "at least one measurement" rule, 1-dp rounding.
 */

test('it accepts a full nine-measurement set and exposes the values', function () {
    $v = VitalSigns::of(
        temperature: 37.2, heartRate: 80, respiratoryRate: 16,
        systolicBp: 120, diastolicBp: 78, oxygenSaturation: 97,
        weight: 70.5, height: 175.0, painScore: 3,
    );

    expect($v->temperature)->toBe(37.2)
        ->and($v->heartRate)->toBe(80)
        ->and($v->respiratoryRate)->toBe(16)
        ->and($v->systolicBp)->toBe(120)
        ->and($v->diastolicBp)->toBe(78)
        ->and($v->oxygenSaturation)->toBe(97)
        ->and($v->weight)->toBe(70.5)
        ->and($v->height)->toBe(175.0)
        ->and($v->painScore)->toBe(3);
});

test('a single measurement satisfies the at-least-one rule', function () {
    expect(VitalSigns::of(heartRate: 72)->heartRate)->toBe(72);
    expect(VitalSigns::of(temperature: 37.0)->temperature)->toBe(37.0);
});

test('an empty set is rejected (ADR-138 D2)', function () {
    expect(fn () => VitalSigns::of())->toThrow(InvalidArgumentException::class, 'at least one');
});

test('temperature / weight / height are rounded to one decimal place (storage precision)', function () {
    $v = VitalSigns::of(temperature: 37.456, weight: 70.449, height: 174.96);

    expect($v->temperature)->toBe(37.5)
        ->and($v->weight)->toBe(70.4)
        ->and($v->height)->toBe(175.0);
});

test('a value outside its structural safety bound is rejected — these are NOT clinical ranges', function (string $field, float|int $value) {
    $args = [$field => $value];
    expect(fn () => VitalSigns::of(...$args))->toThrow(InvalidArgumentException::class);
})->with([
    'temperature too low' => ['temperature', 19.9],
    'temperature too high' => ['temperature', 45.1],
    'heart rate negative' => ['heartRate', -1],
    'heart rate absurd' => ['heartRate', 351],
    'respiratory rate absurd' => ['respiratoryRate', 151],
    'systolic absurd' => ['systolicBp', 301],
    'diastolic absurd' => ['diastolicBp', 251],
    'SpO2 over 100' => ['oxygenSaturation', 101],
    'weight absurd' => ['weight', 500.1],
    'height absurd' => ['height', 300.1],
    'pain over 10' => ['painScore', 11],
]);

test('the boundary values themselves are accepted (bounds are inclusive)', function () {
    $v = VitalSigns::of(
        temperature: 20.0, heartRate: 0, respiratoryRate: 0,
        systolicBp: 0, diastolicBp: 0, oxygenSaturation: 100,
        weight: 0.0, height: 300.0, painScore: 10,
    );
    expect($v->temperature)->toBe(20.0)->and($v->oxygenSaturation)->toBe(100)->and($v->painScore)->toBe(10);
});

test('a physiologically abnormal but structurally valid value is stored, not rejected (no abnormality detection — ADR-138 D5)', function () {
    // e.g. a genuine fever / tachycardia — within the structural bounds, so accepted as recorded
    $v = VitalSigns::of(temperature: 40.5, heartRate: 155, oxygenSaturation: 82);
    expect($v->temperature)->toBe(40.5)->and($v->heartRate)->toBe(155)->and($v->oxygenSaturation)->toBe(82);
});

test('toColumns() maps to the clin_vital_sign_observations column names', function () {
    $cols = VitalSigns::of(temperature: 37.0, heartRate: 70, painScore: 0)->toColumns();

    expect(array_keys($cols))->toBe([
        'temperature', 'heart_rate', 'respiratory_rate', 'systolic_bp', 'diastolic_bp',
        'oxygen_saturation', 'weight', 'height', 'pain_score',
    ])
        ->and($cols['temperature'])->toBe(37.0)
        ->and($cols['heart_rate'])->toBe(70)
        ->and($cols['pain_score'])->toBe(0)
        ->and($cols['weight'])->toBeNull();
});

test('the VO is final readonly with no enumerated cases', function () {
    $r = new ReflectionClass(VitalSigns::class);
    expect($r->isFinal())->toBeTrue()->and($r->isReadOnly())->toBeTrue()->and($r->isEnum())->toBeFalse();
});
