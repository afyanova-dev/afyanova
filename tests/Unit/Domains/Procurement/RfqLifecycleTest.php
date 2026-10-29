<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Exception\IllegalRfqTransition;
use App\Domains\Procurement\Domain\Exception\RfqNotAwardable;
use App\Domains\Procurement\Domain\Exception\RfqNotOpenForQuotation;
use App\Domains\Procurement\Domain\Model\Rfq;
use App\Domains\Procurement\Domain\Service\RfqLifecycle;

$l = new RfqLifecycle;

test('issue only from DRAFT', function () use ($l) {
    $l->assertCanIssue(Rfq::STATUS_DRAFT);
    expect(fn () => $l->assertCanIssue(Rfq::STATUS_ISSUED))->toThrow(IllegalRfqTransition::class);
});

test('close only from ISSUED', function () use ($l) {
    $l->assertCanClose(Rfq::STATUS_ISSUED);
    expect(fn () => $l->assertCanClose(Rfq::STATUS_DRAFT))->toThrow(IllegalRfqTransition::class);
});

test('cancel from DRAFT | ISSUED | CLOSED, never from AWARDED', function () use ($l) {
    foreach ([Rfq::STATUS_DRAFT, Rfq::STATUS_ISSUED, Rfq::STATUS_CLOSED] as $s) {
        $l->assertCanCancel($s);
    }
    foreach ([Rfq::STATUS_AWARDED, Rfq::STATUS_CANCELLED] as $s) {
        expect(fn () => $l->assertCanCancel($s))->toThrow(IllegalRfqTransition::class);
    }
});

test('award only from CLOSED', function () use ($l) {
    $l->assertCanAward('r', Rfq::STATUS_CLOSED);
    expect(fn () => $l->assertCanAward('r', Rfq::STATUS_ISSUED))->toThrow(RfqNotAwardable::class);
    expect(fn () => $l->assertCanAward('r', Rfq::STATUS_AWARDED))->toThrow(RfqNotAwardable::class);
});

test('quotations only while ISSUED', function () use ($l) {
    $l->assertOpenForQuotation('r', Rfq::STATUS_ISSUED);
    expect(fn () => $l->assertOpenForQuotation('r', Rfq::STATUS_CLOSED))->toThrow(RfqNotOpenForQuotation::class);
});
