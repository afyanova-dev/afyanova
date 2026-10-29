<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Exception\RfqAwardInvalid;
use App\Domains\Procurement\Domain\Service\RfqAwardValidator;

$v = new RfqAwardValidator;

// RFQ line L1 wants 100, L2 wants 50. Quotation Q1 offers L1:100, L2:40. Q2 offers L1:60.
$rfqLines = ['L1' => '100', 'L2' => '50'];
$submitted = ['Q1', 'Q2'];
$offers = ['Q1' => ['L1' => '100', 'L2' => '40'], 'Q2' => ['L1' => '60']];

test('a valid split + partial allocation passes', function () use ($v, $rfqLines, $submitted, $offers) {
    $v->assertValid($rfqLines, $submitted, $offers, [
        ['rfqLineId' => 'L1', 'quotationId' => 'Q1', 'awardedQty' => '40'],
        ['rfqLineId' => 'L1', 'quotationId' => 'Q2', 'awardedQty' => '60'],  // split L1 across Q1 + Q2
        ['rfqLineId' => 'L2', 'quotationId' => 'Q1', 'awardedQty' => '30'],  // partial L2 (30 of 50)
    ]);
    expect(true)->toBeTrue();
});

test('empty allocation is rejected', function () use ($v, $rfqLines, $submitted, $offers) {
    expect(fn () => $v->assertValid($rfqLines, $submitted, $offers, []))->toThrow(RfqAwardInvalid::class);
});

test('a duplicate (line, quotation) entry is rejected', function () use ($v, $rfqLines, $submitted, $offers) {
    expect(fn () => $v->assertValid($rfqLines, $submitted, $offers, [
        ['rfqLineId' => 'L1', 'quotationId' => 'Q1', 'awardedQty' => '10'],
        ['rfqLineId' => 'L1', 'quotationId' => 'Q1', 'awardedQty' => '10'],
    ]))->toThrow(RfqAwardInvalid::class);
});

test('awarding a line the quotation did not quote is rejected', function () use ($v, $rfqLines, $submitted, $offers) {
    expect(fn () => $v->assertValid($rfqLines, $submitted, $offers, [
        ['rfqLineId' => 'L2', 'quotationId' => 'Q2', 'awardedQty' => '10'],  // Q2 never quoted L2
    ]))->toThrow(RfqAwardInvalid::class);
});

test('awarding more than the quotation offered is rejected', function () use ($v, $rfqLines, $submitted, $offers) {
    expect(fn () => $v->assertValid($rfqLines, $submitted, $offers, [
        ['rfqLineId' => 'L2', 'quotationId' => 'Q1', 'awardedQty' => '50'],  // Q1 offered only 40
    ]))->toThrow(RfqAwardInvalid::class);
});

test('awarding more than the RFQ line requested (across quotations) is rejected', function () use ($v, $rfqLines, $submitted, $offers) {
    expect(fn () => $v->assertValid($rfqLines, $submitted, $offers, [
        ['rfqLineId' => 'L1', 'quotationId' => 'Q1', 'awardedQty' => '100'],
        ['rfqLineId' => 'L1', 'quotationId' => 'Q2', 'awardedQty' => '10'],  // 110 > 100
    ]))->toThrow(RfqAwardInvalid::class);
});

test('a non-SUBMITTED quotation is rejected', function () use ($v, $rfqLines, $offers) {
    expect(fn () => $v->assertValid($rfqLines, ['Q1'], $offers, [
        ['rfqLineId' => 'L1', 'quotationId' => 'Q2', 'awardedQty' => '10'],
    ]))->toThrow(RfqAwardInvalid::class);
});
