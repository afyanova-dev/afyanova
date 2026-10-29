<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Application\Command\ReverseDispensing;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensingReversed;
use App\Domains\Pharmacy\Domain\Model\DispensingReversal;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('ReverseDispensing validates ids + the reason SET; audit targets the reversal', function () {
    $cmd = new ReverseDispensing(
        reversalId: $r = (string) Str::uuid7(),
        actorId: (string) Str::uuid7(),
        pharmacyOrderId: $o = (string) Str::uuid7(),
        dispensingId: (string) Str::uuid7(),
        reasonCode: 'wrong_item',
    );

    expect($cmd->policyAbility())->toBe('reverse')
        ->and($cmd->subjectId())->toBe($o)
        ->and($cmd->reasonCode)->toBe('WRONG_ITEM')
        ->and($cmd->auditEntry()->action)->toBe('pharmacy.dispensing_reversed')
        ->and($cmd->auditEntry()->targetId)->toBe($r);

    expect(fn () => new ReverseDispensing(
        reversalId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        pharmacyOrderId: (string) Str::uuid7(), dispensingId: (string) Str::uuid7(), reasonCode: 'NONSENSE',
    ))->toThrow(InvalidArgumentException::class, 'Invalid dispensing-reversal reason');
});

test('DispensingReversal::record() builds a COMPLETED write-once row + records MedicationDispensingReversed (PHI-free)', function () {
    $now = CarbonImmutable::now();
    $rev = DispensingReversal::record([
        'id' => (string) Str::uuid7(),
        'tenantId' => (string) Str::uuid7(),
        'facilityId' => (string) Str::uuid7(),
        'dispensingId' => (string) Str::uuid7(),
        'pharmacyOrderId' => (string) Str::uuid7(),
        'prescriptionId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(),
        'reversalNumber' => 'DRV-00000001',
        'itemId' => (string) Str::uuid7(),
        'locationId' => (string) Str::uuid7(),
        'batchNumber' => null,
        'quantity' => '30.0000',
        'reversalMovementId' => (string) Str::uuid7(),
        'reasonCode' => 'DISPENSING_ERROR',
        'reasonNote' => 'keyed the wrong strength',
        'reversedByUserId' => (string) Str::uuid7(),
        'occurredAt' => $now,
        'recordedAt' => $now,
    ]);

    expect($rev->status)->toBe(DispensingReversal::STATUS_COMPLETED)
        ->and((int) $rev->version)->toBe(0)
        ->and($rev->reason_note)->toBe('keyed the wrong strength');

    $events = $rev->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MedicationDispensingReversed::class)
        ->and($events[0]->reasonCode)->toBe('DISPENSING_ERROR')
        ->and($events[0]->quantity)->toBe('30.0000');
});

test('PharmacyOrder::applyReversal() — net 0 → VALIDATED, partial → PARTIALLY_DISPENSED, full → DISPENSED', function () {
    $order = PharmacyOrder::factory()->make(['status' => PharmacyOrderStatus::DISPENSED]);

    $order->applyReversal('0.0000', '30.0000');
    expect($order->status)->toBe(PharmacyOrderStatus::VALIDATED);

    $order->applyReversal('20.0000', '30.0000');
    expect($order->status)->toBe(PharmacyOrderStatus::PARTIALLY_DISPENSED);

    $order->applyReversal('30.0000', '30.0000');
    expect($order->status)->toBe(PharmacyOrderStatus::DISPENSED);

    // null ceiling
    $order->applyReversal('0.0000', null);
    expect($order->status)->toBe(PharmacyOrderStatus::VALIDATED);
});
