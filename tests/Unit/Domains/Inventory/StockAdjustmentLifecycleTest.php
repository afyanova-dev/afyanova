<?php

declare(strict_types=1);

use App\Domains\Inventory\Domain\Exception\IllegalStockAdjustmentTransition;
use App\Domains\Inventory\Domain\Exception\SelfApprovalNotPermitted;
use App\Domains\Inventory\Domain\Model\StockAdjustment;
use App\Domains\Inventory\Domain\Service\StockAdjustmentLifecycle;

beforeEach(function () {
    $this->lifecycle = new StockAdjustmentLifecycle;
});

test('approve / reject are legal only from PENDING_APPROVAL', function () {
    $this->lifecycle->assertCanApprove(StockAdjustment::STATUS_PENDING_APPROVAL);
    $this->lifecycle->assertCanReject(StockAdjustment::STATUS_PENDING_APPROVAL);

    expect(fn () => $this->lifecycle->assertCanApprove(StockAdjustment::STATUS_APPROVED))
        ->toThrow(IllegalStockAdjustmentTransition::class)
        ->and(fn () => $this->lifecycle->assertCanReject(StockAdjustment::STATUS_REJECTED))
        ->toThrow(IllegalStockAdjustmentTransition::class);
});

test('the requester cannot be the approver (§224 / Inv 12)', function () {
    $this->lifecycle->assertNotSelfApproval('adj-1', 'user-a', 'user-b');

    expect(fn () => $this->lifecycle->assertNotSelfApproval('adj-1', 'user-a', 'user-a'))
        ->toThrow(SelfApprovalNotPermitted::class);
});
