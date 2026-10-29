<?php

declare(strict_types=1);

use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;

test('MedicationSubstance creates valid substance with or without coding', function () {
    $uncoded = MedicationSubstance::of('Paracetamol');
    expect($uncoded->name)->toBe('Paracetamol')
        ->and($uncoded->code)->toBeNull()
        ->and($uncoded->codeSystem)->toBeNull()
        ->and($uncoded->isCoded())->toBeFalse();

    $coded = MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm');
    expect($coded->name)->toBe('Amoxicillin')
        ->and($coded->code)->toBe('500123')
        ->and($coded->codeSystem)->toBe('RxNorm')
        ->and($coded->isCoded())->toBeTrue();
});

test('MedicationSubstance rejects blank name', function () {
    MedicationSubstance::of('   ');
})->throws(InvalidArgumentException::class, 'must not be blank');

test('MedicationSubstance rejects code without code_system', function () {
    MedicationSubstance::of('Amoxicillin', '500123', null);
})->throws(InvalidArgumentException::class, 'code requires a code_system');

test('MedicationSubstance rejects code_system without code', function () {
    MedicationSubstance::of('Amoxicillin', null, 'RxNorm');
})->throws(InvalidArgumentException::class, 'code_system without a code is not permitted');
