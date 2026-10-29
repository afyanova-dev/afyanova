<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Exception\IllegalQuotationTransition;
use App\Domains\Procurement\Domain\Model\Quotation;
use App\Domains\Procurement\Domain\Service\QuotationLifecycle;

$l = new QuotationLifecycle;

test('revise only while RECEIVED', function () use ($l) {
    $l->assertCanRevise(Quotation::STATUS_RECEIVED);
    foreach ([Quotation::STATUS_SUBMITTED, Quotation::STATUS_AWARDED, Quotation::STATUS_NOT_AWARDED, Quotation::STATUS_WITHDRAWN] as $s) {
        expect(fn () => $l->assertCanRevise($s))->toThrow(IllegalQuotationTransition::class);
    }
});

test('withdraw only while RECEIVED', function () use ($l) {
    $l->assertCanWithdraw(Quotation::STATUS_RECEIVED);
    foreach ([Quotation::STATUS_SUBMITTED, Quotation::STATUS_AWARDED, Quotation::STATUS_NOT_AWARDED, Quotation::STATUS_WITHDRAWN] as $s) {
        expect(fn () => $l->assertCanWithdraw($s))->toThrow(IllegalQuotationTransition::class);
    }
});

test('assertSubmittedForAward accepts only SUBMITTED (the AwardRfq guard)', function () use ($l) {
    $l->assertSubmittedForAward(Quotation::STATUS_SUBMITTED);
    foreach ([Quotation::STATUS_RECEIVED, Quotation::STATUS_AWARDED, Quotation::STATUS_NOT_AWARDED, Quotation::STATUS_WITHDRAWN] as $s) {
        expect(fn () => $l->assertSubmittedForAward($s))->toThrow(IllegalQuotationTransition::class);
    }
});
