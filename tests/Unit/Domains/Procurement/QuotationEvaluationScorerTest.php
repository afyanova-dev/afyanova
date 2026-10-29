<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\EvaluationCriteria;
use App\Domains\Procurement\Domain\EvaluationScores;
use App\Domains\Procurement\Domain\Service\QuotationEvaluationScorer;

$s = new QuotationEvaluationScorer;
$defaults = EvaluationCriteria::defaults();

test('overall is the default weighted mean (40 / 30 / 15 / 15), rounded', function () use ($s, $defaults) {
    expect($s->overall(new EvaluationScores(100, 0, 0, 0), $defaults))->toBe(40);
    expect($s->overall(new EvaluationScores(80, 80, 80, 80), $defaults))->toBe(80);
    // 90*40 + 70*30 + 60*15 + 50*15 = 3600 + 2100 + 900 + 750 = 7350 / 100 = 73.5 → 74
    expect($s->overall(new EvaluationScores(90, 70, 60, 50), $defaults))->toBe(74);
});

test('overall over the PRESENT dimensions only when a dimension is null (optional)', function () use ($s, $defaults) {
    // quality absent → (90*40 + 70*30 + 50*15) / (40+30+15) = (3600 + 2100 + 750) / 85 = 6450/85 = 75.88 → 76
    expect($s->overall(new EvaluationScores(90, 70, null, 50), $defaults))->toBe(76);
    // only commercial present → its own value
    expect($s->overall(new EvaluationScores(64, null, null, null), $defaults))->toBe(64);
});

test('custom tenant weights change the overall', function () use ($s) {
    $criteria = new EvaluationCriteria(
        commercialWeight: 10, technicalWeight: 10, qualityWeight: 10, deliveryWeight: 70,
        commercialRequired: true, technicalRequired: true, qualityRequired: true, deliveryRequired: true,
        passThreshold: null, requiredAbove: null,
    );
    // 100*10 + 0*10 + 0*10 + 100*70 = 1000 + 7000 = 8000 / 100 = 80
    expect($s->overall(new EvaluationScores(100, 0, 0, 100), $criteria))->toBe(80);
});

test('a dimension score outside 0..100 is rejected', function () use ($s, $defaults) {
    expect(fn () => $s->overall(new EvaluationScores(101, 50, 50, 50), $defaults))->toThrow(InvalidArgumentException::class);
    $s->assertScoreInRange(0, 'commercial');
    $s->assertScoreInRange(100, 'delivery');
    expect(fn () => $s->assertScoreInRange(255, 'quality'))->toThrow(InvalidArgumentException::class);
});
