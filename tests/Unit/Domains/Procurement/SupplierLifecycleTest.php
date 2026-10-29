<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Exception\IllegalSupplierTransition;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Domains\Procurement\Domain\Service\SupplierLifecycle;

$lifecycle = new SupplierLifecycle;

dataset('legal supplier transitions', [
    [Supplier::STATUS_ACTIVE, Supplier::STATUS_SUSPENDED],
    [Supplier::STATUS_ACTIVE, Supplier::STATUS_INACTIVE],
    [Supplier::STATUS_ACTIVE, Supplier::STATUS_BLACKLISTED],
    [Supplier::STATUS_SUSPENDED, Supplier::STATUS_ACTIVE],
    [Supplier::STATUS_SUSPENDED, Supplier::STATUS_INACTIVE],
    [Supplier::STATUS_SUSPENDED, Supplier::STATUS_BLACKLISTED],
    [Supplier::STATUS_INACTIVE, Supplier::STATUS_ACTIVE],
]);

dataset('illegal supplier transitions', [
    [Supplier::STATUS_INACTIVE, Supplier::STATUS_SUSPENDED],
    [Supplier::STATUS_INACTIVE, Supplier::STATUS_BLACKLISTED],
    [Supplier::STATUS_BLACKLISTED, Supplier::STATUS_ACTIVE],
    [Supplier::STATUS_BLACKLISTED, Supplier::STATUS_SUSPENDED],
    [Supplier::STATUS_BLACKLISTED, Supplier::STATUS_INACTIVE],
    [Supplier::STATUS_ACTIVE, Supplier::STATUS_ACTIVE],
]);

test('legal supplier transitions pass', function (string $from, string $to) use ($lifecycle) {
    $lifecycle->assertCanTransition($from, $to);
    expect(true)->toBeTrue();
})->with('legal supplier transitions');

test('illegal supplier transitions throw — BLACKLISTED is terminal', function (string $from, string $to) use ($lifecycle) {
    expect(fn () => $lifecycle->assertCanTransition($from, $to))
        ->toThrow(IllegalSupplierTransition::class);
})->with('illegal supplier transitions');
