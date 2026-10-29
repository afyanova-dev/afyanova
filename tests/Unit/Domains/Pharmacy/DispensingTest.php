<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Contracts\Event\MedicationDispensed;
use App\Domains\Pharmacy\Domain\Model\Dispensing;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\ValueObject\DispensingStatus;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function dispensingPayload(array $over = []): array
{
    $now = CarbonImmutable::now();

    return array_merge([
        'id' => (string) Str::uuid7(),
        'tenantId' => (string) Str::uuid7(),
        'facilityId' => (string) Str::uuid7(),
        'pharmacyOrderId' => (string) Str::uuid7(),
        'prescriptionId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'dispensingNumber' => 'DSP-00000001',
        'itemId' => (string) Str::uuid7(),
        'locationId' => (string) Str::uuid7(),
        'batchNumber' => null,
        'quantity' => '10.0000',
        'quantityUnit' => null,
        'authorizedQuantityValue' => null,
        'authorizedQuantityUnit' => null,
        'reservationId' => (string) Str::uuid7(),
        'movementId' => (string) Str::uuid7(),
        'financialClearanceRef' => null,
        'dispensedByUserId' => (string) Str::uuid7(),
        'occurredAt' => $now,
        'recordedAt' => $now,
    ], $over);
}

test('Dispensing::record() builds a COMPLETED write-once row and records MedicationDispensed (PHI-free)', function () {
    $d = Dispensing::record(dispensingPayload(['quantity' => '10.0000']));

    expect($d->status)->toBe(DispensingStatus::COMPLETED)
        ->and((int) $d->version)->toBe(0)
        ->and($d->dispensed_without_ceiling)->toBeTrue(); // null authorised value

    $events = $d->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MedicationDispensed::class)
        ->and($events[0]->dispensingId)->toBe((string) $d->id)
        ->and($events[0]->quantity)->toBe('10.0000');

    // a structured authorised value clears the "no ceiling" flag
    $withCeiling = Dispensing::record(dispensingPayload([
        'authorizedQuantityValue' => '30.0000', 'authorizedQuantityUnit' => 'tablet',
    ]));
    expect($withCeiling->dispensed_without_ceiling)->toBeFalse();
});

test('PharmacyOrder::applyDispensing() advances the rollup — DISPENSED only when the ceiling is met', function () {
    $order = PharmacyOrder::factory()->make(['status' => PharmacyOrderStatus::VALIDATED]);

    $order->applyDispensing('10.0000', '30.0000');
    expect($order->status)->toBe(PharmacyOrderStatus::PARTIALLY_DISPENSED);

    $order->applyDispensing('30.0000', '30.0000');
    expect($order->status)->toBe(PharmacyOrderStatus::DISPENSED);

    // no ceiling → never DISPENSED here
    $order2 = PharmacyOrder::factory()->make(['status' => PharmacyOrderStatus::VALIDATED]);
    $order2->applyDispensing('999.0000', null);
    expect($order2->status)->toBe(PharmacyOrderStatus::PARTIALLY_DISPENSED);
});
