<?php

declare(strict_types=1);

use App\Domains\Laboratory\Domain\Exception\IllegalLabOrderTransition;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Service\LabOrderLifecycle;

beforeEach(function () {
    $this->lifecycle = new LabOrderLifecycle;
});

test('assertCanSubmit allows only DRAFT', function () {
    $this->lifecycle->assertCanSubmit(LabOrder::STATUS_DRAFT);

    foreach ([LabOrder::STATUS_ORDERED, LabOrder::STATUS_ACCEPTED, LabOrder::STATUS_REJECTED, LabOrder::STATUS_CANCELLED] as $status) {
        expect(fn () => $this->lifecycle->assertCanSubmit($status))->toThrow(IllegalLabOrderTransition::class);
    }
});

test('assertCanAccept and assertCanReject allow only ORDERED', function () {
    $this->lifecycle->assertCanAccept(LabOrder::STATUS_ORDERED);
    $this->lifecycle->assertCanReject(LabOrder::STATUS_ORDERED);

    foreach ([LabOrder::STATUS_DRAFT, LabOrder::STATUS_ACCEPTED, LabOrder::STATUS_REJECTED, LabOrder::STATUS_CANCELLED] as $status) {
        expect(fn () => $this->lifecycle->assertCanAccept($status))->toThrow(IllegalLabOrderTransition::class);
        expect(fn () => $this->lifecycle->assertCanReject($status))->toThrow(IllegalLabOrderTransition::class);
    }
});

test('assertCanCancel allows DRAFT and ORDERED only', function () {
    $this->lifecycle->assertCanCancel(LabOrder::STATUS_DRAFT);
    $this->lifecycle->assertCanCancel(LabOrder::STATUS_ORDERED);

    foreach ([LabOrder::STATUS_ACCEPTED, LabOrder::STATUS_REJECTED, LabOrder::STATUS_CANCELLED] as $status) {
        expect(fn () => $this->lifecycle->assertCanCancel($status))->toThrow(IllegalLabOrderTransition::class);
    }
});
