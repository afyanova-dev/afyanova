<?php

declare(strict_types=1);

use App\Domains\Laboratory\Database\Factories\LabResultFactory;
use App\Domains\Laboratory\Domain\Exception\IllegalLabResultTransition;
use App\Domains\Laboratory\Domain\Exception\LabResultNotReleasable;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Service\LabResultLifecycle;
use App\Domains\Laboratory\Domain\Service\LabResultReleaseGate;

beforeEach(function () {
    $this->lifecycle = new LabResultLifecycle;
    $this->gate = new LabResultReleaseGate;
});

test('assertCanVerify allows ENTERED and REVIEWED only', function () {
    $this->lifecycle->assertCanVerify(LabResult::STATUS_ENTERED);
    $this->lifecycle->assertCanVerify(LabResult::STATUS_REVIEWED);

    foreach ([LabResult::STATUS_VERIFIED, LabResult::STATUS_RELEASED] as $status) {
        expect(fn () => $this->lifecycle->assertCanVerify($status))->toThrow(IllegalLabResultTransition::class);
    }
});

test('assertCanRelease allows only VERIFIED', function () {
    $this->lifecycle->assertCanRelease(LabResult::STATUS_VERIFIED);

    foreach ([LabResult::STATUS_ENTERED, LabResult::STATUS_REVIEWED, LabResult::STATUS_RELEASED] as $status) {
        expect(fn () => $this->lifecycle->assertCanRelease($status))->toThrow(IllegalLabResultTransition::class);
    }
});

test('the release gate blocks an unverified result and one on hold', function () {
    /** @var LabResult $verified */
    $verified = LabResultFactory::new()->makeOne(['status' => LabResult::STATUS_VERIFIED, 'release_hold_reason' => null]);
    $this->gate->assertReleasable($verified);

    /** @var LabResult $entered */
    $entered = LabResultFactory::new()->makeOne(['status' => LabResult::STATUS_ENTERED, 'release_hold_reason' => null]);
    expect(fn () => $this->gate->assertReleasable($entered))->toThrow(LabResultNotReleasable::class);

    /** @var LabResult $held */
    $held = LabResultFactory::new()->makeOne(['status' => LabResult::STATUS_VERIFIED, 'release_hold_reason' => 'QC_FAILED']);
    expect(fn () => $this->gate->assertReleasable($held))->toThrow(LabResultNotReleasable::class);
});
