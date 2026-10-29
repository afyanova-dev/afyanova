<?php

declare(strict_types=1);

use App\Domains\Laboratory\Domain\Exception\IllegalLabSpecimenTransition;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Domains\Laboratory\Domain\Service\LabSpecimenLifecycle;

beforeEach(function () {
    $this->lifecycle = new LabSpecimenLifecycle;
});

test('assertCanReceive allows only COLLECTED', function () {
    $this->lifecycle->assertCanReceive(LabSpecimen::STATUS_COLLECTED);

    foreach ([LabSpecimen::STATUS_RECEIVED, LabSpecimen::STATUS_ACCEPTED, LabSpecimen::STATUS_REJECTED] as $status) {
        expect(fn () => $this->lifecycle->assertCanReceive($status))->toThrow(IllegalLabSpecimenTransition::class);
    }
});

test('assertCanAccept allows only RECEIVED', function () {
    $this->lifecycle->assertCanAccept(LabSpecimen::STATUS_RECEIVED);

    foreach ([LabSpecimen::STATUS_COLLECTED, LabSpecimen::STATUS_ACCEPTED, LabSpecimen::STATUS_REJECTED] as $status) {
        expect(fn () => $this->lifecycle->assertCanAccept($status))->toThrow(IllegalLabSpecimenTransition::class);
    }
});

test('assertCanReject allows COLLECTED and RECEIVED only', function () {
    $this->lifecycle->assertCanReject(LabSpecimen::STATUS_COLLECTED);
    $this->lifecycle->assertCanReject(LabSpecimen::STATUS_RECEIVED);

    foreach ([LabSpecimen::STATUS_ACCEPTED, LabSpecimen::STATUS_REJECTED] as $status) {
        expect(fn () => $this->lifecycle->assertCanReject($status))->toThrow(IllegalLabSpecimenTransition::class);
    }
});

test('assertCanRequestRecollection allows only REJECTED', function () {
    $this->lifecycle->assertCanRequestRecollection(LabSpecimen::STATUS_REJECTED);

    foreach ([LabSpecimen::STATUS_COLLECTED, LabSpecimen::STATUS_RECEIVED, LabSpecimen::STATUS_ACCEPTED] as $status) {
        expect(fn () => $this->lifecycle->assertCanRequestRecollection($status))->toThrow(IllegalLabSpecimenTransition::class);
    }
});
