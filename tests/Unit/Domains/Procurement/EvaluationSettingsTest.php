<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\EvaluationCriteria;
use App\Domains\Procurement\Domain\Exception\EvaluationSettingsInvalid;
use App\Domains\Procurement\Domain\Model\EvaluationSettings;
use Illuminate\Support\Str;

test('initialise seeds the Proc.2b defaults and version 1', function () {
    $s = EvaluationSettings::initialise((string) Str::uuid7(), (string) Str::uuid7());

    expect($s->commercial_weight)->toBe(40)
        ->and($s->technical_weight)->toBe(30)
        ->and($s->quality_weight)->toBe(15)
        ->and($s->delivery_weight)->toBe(15)
        ->and($s->commercial_required)->toBeTrue()
        ->and($s->pass_threshold)->toBeNull()
        ->and($s->evaluation_required_above)->toBeNull()
        ->and((int) $s->version)->toBe(1);

    $criteria = $s->toCriteria();
    expect($criteria)->toBeInstanceOf(EvaluationCriteria::class)
        ->and($criteria->mandatoryDimensions())->toBe(['commercial', 'technical', 'quality', 'delivery']);
});

test('revise patches, bumps version, and re-validates', function () {
    $s = EvaluationSettings::initialise((string) Str::uuid7(), (string) Str::uuid7());

    $s->revise(
        weights: ['commercial' => 50, 'quality' => 5],
        mandatory: ['delivery' => false],
        passThreshold: '60',
        evaluationRequiredAbove: '5000000',
    );

    expect($s->commercial_weight)->toBe(50)
        ->and($s->technical_weight)->toBe(30)     // untouched
        ->and($s->quality_weight)->toBe(5)
        ->and($s->delivery_required)->toBeFalse()
        ->and($s->pass_threshold)->toBe(60)
        ->and((string) $s->evaluation_required_above)->toBe('5000000.00')
        ->and((int) $s->version)->toBe(2);

    $s->revise(passThreshold: '');   // clear
    expect($s->pass_threshold)->toBeNull()->and((int) $s->version)->toBe(3);
});

test('the scoring invariants are enforced', function () {
    $s = EvaluationSettings::initialise((string) Str::uuid7(), (string) Str::uuid7());

    expect(fn () => $s->revise(weights: ['commercial' => 200]))->toThrow(EvaluationSettingsInvalid::class);
    expect(fn () => $s->revise(weights: ['commercial' => 0, 'technical' => 0, 'quality' => 0, 'delivery' => 0]))
        ->toThrow(EvaluationSettingsInvalid::class);
    expect(fn () => $s->revise(mandatory: ['commercial' => false, 'technical' => false, 'quality' => false, 'delivery' => false]))
        ->toThrow(EvaluationSettingsInvalid::class);
    expect(fn () => $s->revise(passThreshold: '150'))->toThrow(EvaluationSettingsInvalid::class);
});
