<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Application\Command\RecordPickup;
use App\Domains\Pharmacy\Contracts\Event\MedicationPickedUp;
use App\Domains\Pharmacy\Domain\Exception\IllegalPickupTransition;
use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Domains\Pharmacy\Domain\Service\PickupLifecycle;
use App\Domains\Pharmacy\Domain\ValueObject\PickupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

test('RecordPickup validates ids + the recipient SET; audit targets the pickup', function () {
    $cmd = new RecordPickup(
        actorId: (string) Str::uuid7(),
        pharmacyOrderId: $o = (string) Str::uuid7(),
        dispensingId: $d = (string) Str::uuid7(),
        recipientType: 'guardian',
    );

    expect($cmd->policyAbility())->toBe('pickup')
        ->and($cmd->subjectId())->toBe($o)
        ->and($cmd->recipientType)->toBe('GUARDIAN')
        ->and($cmd->auditEntry()->action)->toBe('pharmacy.medication_picked_up')
        ->and($cmd->auditEntry()->targetId)->toBe($d);

    expect(fn () => new RecordPickup(
        actorId: (string) Str::uuid7(), pharmacyOrderId: (string) Str::uuid7(),
        dispensingId: (string) Str::uuid7(), recipientType: 'NONSENSE',
    ))->toThrow(InvalidArgumentException::class, 'Invalid pickup recipient');
});

test('Pickup::open() is READY_FOR_PICKUP with no event; recordCollection() → PICKED_UP + MedicationPickedUp (PHI-free)', function () {
    $now = CarbonImmutable::now();
    $p = Pickup::open(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), facilityId: (string) Str::uuid7(),
        dispensingId: (string) Str::uuid7(), pharmacyOrderId: (string) Str::uuid7(),
        prescriptionId: (string) Str::uuid7(), patientId: (string) Str::uuid7(),
        pickupNumber: 'PKP-00000001', occurredAt: $now, recordedAt: $now,
    );
    expect($p->status)->toBe(PickupStatus::READY_FOR_PICKUP)
        ->and($p->releaseEvents())->toHaveCount(0);

    $p->recordCollection(
        collectedByUserId: $u = (string) Str::uuid7(),
        recipientType: 'PATIENT', recipientName: null, verificationNote: 'ID checked', collectedAt: $now,
    );
    expect($p->status)->toBe(PickupStatus::PICKED_UP)
        ->and((string) $p->collected_by_user_id)->toBe($u)
        ->and($p->verification_note)->toBe('ID checked');

    $events = $p->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MedicationPickedUp::class)
        ->and($events[0]->recipientType)->toBe('PATIENT');

    // markNotCollected / cancel record NO event
    $p2 = Pickup::open(
        id: (string) Str::uuid7(), tenantId: (string) Str::uuid7(), facilityId: (string) Str::uuid7(),
        dispensingId: (string) Str::uuid7(), pharmacyOrderId: (string) Str::uuid7(),
        prescriptionId: (string) Str::uuid7(), patientId: (string) Str::uuid7(),
        pickupNumber: 'PKP-00000002', occurredAt: $now, recordedAt: $now,
    );
    $p2->markNotCollected('PATIENT_NO_SHOW', null);
    expect($p2->status)->toBe(PickupStatus::NOT_COLLECTED)->and($p2->releaseEvents())->toHaveCount(0);
});

test('PickupLifecycle: RecordPickup accepts {READY_FOR_PICKUP, NOT_COLLECTED}; the others only READY_FOR_PICKUP', function () {
    $l = new PickupLifecycle;

    $l->assertCanRecord(PickupStatus::READY_FOR_PICKUP);
    $l->assertCanRecord(PickupStatus::NOT_COLLECTED);
    expect(fn () => $l->assertCanRecord(PickupStatus::PICKED_UP))->toThrow(IllegalPickupTransition::class);
    expect(fn () => $l->assertCanRecord(PickupStatus::CANCELLED))->toThrow(IllegalPickupTransition::class);

    $l->assertCanMarkNotCollected(PickupStatus::READY_FOR_PICKUP);
    expect(fn () => $l->assertCanMarkNotCollected(PickupStatus::NOT_COLLECTED))->toThrow(IllegalPickupTransition::class);

    $l->assertCanCancel(PickupStatus::READY_FOR_PICKUP);
    $l->assertCanCancel(PickupStatus::NOT_COLLECTED);
    expect(fn () => $l->assertCanCancel(PickupStatus::PICKED_UP))->toThrow(IllegalPickupTransition::class);
});
