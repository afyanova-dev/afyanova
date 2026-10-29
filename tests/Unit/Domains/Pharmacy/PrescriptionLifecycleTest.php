<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Domain\Exception\IllegalPrescriptionStatusTransition;
use App\Domains\Pharmacy\Domain\Service\PrescriptionLifecycle;
use App\Domains\Pharmacy\Domain\ValueObject\PrescriptionStatus;

$l = new PrescriptionLifecycle;

test('assertAcceptable accepts only RECEIVED', function () use ($l) {
    $l->assertAcceptable(PrescriptionStatus::RECEIVED);

    foreach ([PrescriptionStatus::ACTIVE, PrescriptionStatus::CANCELLED] as $status) {
        expect(fn () => $l->assertAcceptable($status))->toThrow(IllegalPrescriptionStatusTransition::class);
    }
});

test('assertCancellable accepts RECEIVED and ACTIVE, not CANCELLED', function () use ($l) {
    $l->assertCancellable(PrescriptionStatus::RECEIVED);
    $l->assertCancellable(PrescriptionStatus::ACTIVE);

    expect(fn () => $l->assertCancellable(PrescriptionStatus::CANCELLED))
        ->toThrow(IllegalPrescriptionStatusTransition::class);
});
