<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Exception\IllegalRequisitionTransition;
use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Domains\Procurement\Domain\Service\RequisitionLifecycle;

$l = new RequisitionLifecycle;

test('submit only from DRAFT', function () use ($l) {
    $l->assertCanSubmit(PurchaseRequisition::STATUS_DRAFT);
    expect(fn () => $l->assertCanSubmit(PurchaseRequisition::STATUS_APPROVED))
        ->toThrow(IllegalRequisitionTransition::class);
});

test('approve + reject only from SUBMITTED', function () use ($l) {
    $l->assertCanApprove(PurchaseRequisition::STATUS_SUBMITTED);
    $l->assertCanReject(PurchaseRequisition::STATUS_SUBMITTED);
    expect(fn () => $l->assertCanApprove(PurchaseRequisition::STATUS_DRAFT))->toThrow(IllegalRequisitionTransition::class);
    expect(fn () => $l->assertCanReject(PurchaseRequisition::STATUS_APPROVED))->toThrow(IllegalRequisitionTransition::class);
});

test('cancel from DRAFT or SUBMITTED, never from APPROVED / REJECTED', function () use ($l) {
    $l->assertCanCancel(PurchaseRequisition::STATUS_DRAFT);
    $l->assertCanCancel(PurchaseRequisition::STATUS_SUBMITTED);
    expect(fn () => $l->assertCanCancel(PurchaseRequisition::STATUS_APPROVED))->toThrow(IllegalRequisitionTransition::class);
    expect(fn () => $l->assertCanCancel(PurchaseRequisition::STATUS_REJECTED))->toThrow(IllegalRequisitionTransition::class);
});
