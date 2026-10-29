<?php

declare(strict_types=1);

use App\Domains\Radiology\Contracts\Event\ImagingOrderCreated;
use App\Domains\Radiology\Domain\Exception\IllegalImagingOrderTransition;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Service\ImagingOrderLifecycle;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->lifecycle = new ImagingOrderLifecycle;
});

test('the front of the machine: DRAFT -> ORDERED -> ACCEPTED', function () {
    $this->lifecycle->assertCanSubmit(ImagingOrder::STATUS_DRAFT);
    $this->lifecycle->assertCanAccept(ImagingOrder::STATUS_ORDERED);
    $this->lifecycle->assertCanReject(ImagingOrder::STATUS_ORDERED);

    expect(fn () => $this->lifecycle->assertCanSubmit(ImagingOrder::STATUS_ORDERED))
        ->toThrow(IllegalImagingOrderTransition::class)
        ->and(fn () => $this->lifecycle->assertCanAccept(ImagingOrder::STATUS_DRAFT))
        ->toThrow(IllegalImagingOrderTransition::class)
        ->and(fn () => $this->lifecycle->assertCanAccept(ImagingOrder::STATUS_ACCEPTED))
        ->toThrow(IllegalImagingOrderTransition::class)
        ->and(fn () => $this->lifecycle->assertCanReject(ImagingOrder::STATUS_ACCEPTED))
        ->toThrow(IllegalImagingOrderTransition::class);
});

test('cancel is allowed from DRAFT or ORDERED only', function () {
    $this->lifecycle->assertCanCancel(ImagingOrder::STATUS_DRAFT);
    $this->lifecycle->assertCanCancel(ImagingOrder::STATUS_ORDERED);

    foreach ([ImagingOrder::STATUS_ACCEPTED, ImagingOrder::STATUS_REJECTED, ImagingOrder::STATUS_CANCELLED] as $status) {
        expect(fn () => $this->lifecycle->assertCanCancel($status))->toThrow(IllegalImagingOrderTransition::class);
    }
});

test('create() records a PHI-free ImagingOrderCreated and starts DRAFT', function () {
    $order = ImagingOrder::create(
        id: (string) Str::uuid7(),
        tenantId: (string) Str::uuid7(),
        facilityId: (string) Str::uuid7(),
        patientId: (string) Str::uuid7(),
        encounterId: (string) Str::uuid7(),
        orderNumber: 'RAD-00000001',
        priority: 'ROUTINE',
        clinicalIndicationRef: null,
        clinicalIndicationText: 'Suspected pneumonia — free text.',
        createdByUserId: (string) Str::uuid7(),
    );

    expect($order->status)->toBe(ImagingOrder::STATUS_DRAFT);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ImagingOrderCreated::class)
        ->and($events[0]->orderNumber)->toBe('RAD-00000001')
        ->and(json_encode($events[0]))->not->toContain('pneumonia');
});
