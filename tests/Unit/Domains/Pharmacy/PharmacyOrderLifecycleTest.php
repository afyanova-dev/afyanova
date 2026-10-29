<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Domain\Exception\IllegalPharmacyOrderStatusTransition;
use App\Domains\Pharmacy\Domain\Service\PharmacyOrderLifecycle;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;

$l = new PharmacyOrderLifecycle;

test('assertValidatable accepts only RECEIVED', function () use ($l) {
    $l->assertValidatable(PharmacyOrderStatus::RECEIVED);

    foreach ([
        PharmacyOrderStatus::VALIDATED,
        PharmacyOrderStatus::REJECTED,
        PharmacyOrderStatus::ON_HOLD,
        PharmacyOrderStatus::CANCELLED,
    ] as $status) {
        expect(fn () => $l->assertValidatable($status))->toThrow(IllegalPharmacyOrderStatusTransition::class);
    }
});

test('reject / hold accept RECEIVED and VALIDATED only', function () use ($l) {
    foreach ([PharmacyOrderStatus::RECEIVED, PharmacyOrderStatus::VALIDATED] as $status) {
        $l->assertRejectable($status);
        $l->assertHoldable($status);
    }

    foreach ([PharmacyOrderStatus::ON_HOLD, PharmacyOrderStatus::REJECTED, PharmacyOrderStatus::CANCELLED] as $status) {
        expect(fn () => $l->assertRejectable($status))->toThrow(IllegalPharmacyOrderStatusTransition::class);
        expect(fn () => $l->assertHoldable($status))->toThrow(IllegalPharmacyOrderStatusTransition::class);
    }
});

test('assertReleasable accepts only ON_HOLD; assertCancellable accepts any non-terminal', function () use ($l) {
    $l->assertReleasable(PharmacyOrderStatus::ON_HOLD);
    expect(fn () => $l->assertReleasable(PharmacyOrderStatus::RECEIVED))
        ->toThrow(IllegalPharmacyOrderStatusTransition::class);

    foreach ([PharmacyOrderStatus::RECEIVED, PharmacyOrderStatus::VALIDATED, PharmacyOrderStatus::ON_HOLD] as $status) {
        $l->assertCancellable($status);
    }
    foreach ([PharmacyOrderStatus::REJECTED, PharmacyOrderStatus::CANCELLED] as $status) {
        expect(fn () => $l->assertCancellable($status))->toThrow(IllegalPharmacyOrderStatusTransition::class);
    }
});
