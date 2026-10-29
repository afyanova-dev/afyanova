<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Exception\IllegalPurchaseOrderTransition;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Service\PurchaseOrderLifecycle;

$lifecycle = new PurchaseOrderLifecycle;

test('submit is legal only from DRAFT', function () use ($lifecycle) {
    $lifecycle->assertCanSubmit(PurchaseOrder::STATUS_DRAFT);
    expect(fn () => $lifecycle->assertCanSubmit(PurchaseOrder::STATUS_SUBMITTED))
        ->toThrow(IllegalPurchaseOrderTransition::class);
});

test('approve is legal only from SUBMITTED', function () use ($lifecycle) {
    $lifecycle->assertCanApprove(PurchaseOrder::STATUS_SUBMITTED);
    expect(fn () => $lifecycle->assertCanApprove(PurchaseOrder::STATUS_DRAFT))
        ->toThrow(IllegalPurchaseOrderTransition::class);
});

test('issue is legal only from APPROVED', function () use ($lifecycle) {
    $lifecycle->assertCanIssue(PurchaseOrder::STATUS_APPROVED);
    expect(fn () => $lifecycle->assertCanIssue(PurchaseOrder::STATUS_SUBMITTED))
        ->toThrow(IllegalPurchaseOrderTransition::class);
});

test('amend is legal ONLY from ISSUED', function () use ($lifecycle) {
    $lifecycle->assertCanAmend(PurchaseOrder::STATUS_ISSUED);
    foreach ([
        PurchaseOrder::STATUS_DRAFT,
        PurchaseOrder::STATUS_SUBMITTED,
        PurchaseOrder::STATUS_APPROVED,
        PurchaseOrder::STATUS_CANCELLED,
    ] as $status) {
        expect(fn () => $lifecycle->assertCanAmend($status))
            ->toThrow(IllegalPurchaseOrderTransition::class);
    }
});

test('cancel is legal through PARTIALLY_RECEIVED, not from CANCELLED / FULLY_RECEIVED', function () use ($lifecycle) {
    foreach ([
        PurchaseOrder::STATUS_DRAFT,
        PurchaseOrder::STATUS_SUBMITTED,
        PurchaseOrder::STATUS_APPROVED,
        PurchaseOrder::STATUS_ISSUED,
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    ] as $status) {
        $lifecycle->assertCanCancel($status);
    }
    foreach ([PurchaseOrder::STATUS_CANCELLED, PurchaseOrder::STATUS_FULLY_RECEIVED] as $status) {
        expect(fn () => $lifecycle->assertCanCancel($status))
            ->toThrow(IllegalPurchaseOrderTransition::class);
    }
});

test('receipt progress is legal only from ISSUED or PARTIALLY_RECEIVED (ADR-172 D-F)', function () use ($lifecycle) {
    $lifecycle->assertCanRecordReceipt(PurchaseOrder::STATUS_ISSUED);
    $lifecycle->assertCanRecordReceipt(PurchaseOrder::STATUS_PARTIALLY_RECEIVED);
    foreach ([
        PurchaseOrder::STATUS_DRAFT,
        PurchaseOrder::STATUS_APPROVED,
        PurchaseOrder::STATUS_FULLY_RECEIVED,
        PurchaseOrder::STATUS_CANCELLED,
    ] as $status) {
        expect(fn () => $lifecycle->assertCanRecordReceipt($status))
            ->toThrow(IllegalPurchaseOrderTransition::class);
    }
});
