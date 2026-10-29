<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Exception\IllegalEvaluationTransition;
use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Domains\Procurement\Domain\Service\EvaluationLifecycle;

$l = new EvaluationLifecycle;

test('revise only while DRAFT', function () use ($l) {
    $l->assertCanRevise(QuotationEvaluation::STATUS_DRAFT);
    expect(fn () => $l->assertCanRevise(QuotationEvaluation::STATUS_SUBMITTED))
        ->toThrow(IllegalEvaluationTransition::class);
});

test('submit only while DRAFT', function () use ($l) {
    $l->assertCanSubmit(QuotationEvaluation::STATUS_DRAFT);
    expect(fn () => $l->assertCanSubmit(QuotationEvaluation::STATUS_SUBMITTED))
        ->toThrow(IllegalEvaluationTransition::class);
});
