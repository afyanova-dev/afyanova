<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\AmendPurchaseOrder;
use App\Domains\Procurement\Domain\Model\PoAmendment;
use Illuminate\Support\Str;

$po = (string) Str::uuid7();
$actor = (string) Str::uuid7();
$line = (string) Str::uuid7();

test('OTHER and unknown amendment types are rejected', function () use ($po, $actor, $line) {
    expect(fn () => new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_OTHER, 'r', '1', $line))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new AmendPurchaseOrder($po, $actor, 'BOGUS', 'r', '1', $line))
        ->toThrow(InvalidArgumentException::class);
});

test('line-level types require a poLineId; NOTES forbids one', function () use ($po, $actor, $line) {
    expect(fn () => new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_QUANTITY, 'r', '5', null))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_NOTES, 'r', 'hello', $line))
        ->toThrow(InvalidArgumentException::class);

    $ok = new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_NOTES, 'r', 'hello', null);
    expect($ok->isLineLevel())->toBeFalse()->and($ok->targetField())->toBe('notes');
});

test('QUANTITY must be > 0, PRICE must be >= 0', function () use ($po, $actor, $line) {
    expect(fn () => new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_QUANTITY, 'r', '0', $line))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_PRICE, 'r', '-1', $line))
        ->toThrow(InvalidArgumentException::class);

    $price = new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_PRICE, 'r', '0', $line);
    expect($price->targetField())->toBe('unit_price')->and($price->isLineLevel())->toBeTrue();
});

test('reason is mandatory', function () use ($po, $actor, $line) {
    expect(fn () => new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_QUANTITY, '  ', '5', $line))
        ->toThrow(InvalidArgumentException::class);
});

test('DELIVERY_LOCATION new value must be a UUID or clearable', function () use ($po, $actor, $line) {
    expect(fn () => new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_DELIVERY_LOCATION, 'r', 'not-a-uuid', $line))
        ->toThrow(InvalidArgumentException::class);
    $clear = new AmendPurchaseOrder($po, $actor, PoAmendment::TYPE_DELIVERY_LOCATION, 'r', null, $line);
    expect($clear->targetField())->toBe('delivery_location_ref');
});
