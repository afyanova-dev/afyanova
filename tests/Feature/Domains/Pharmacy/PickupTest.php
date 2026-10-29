<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Application\Command\CancelPickup;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\Dispense;
use App\Domains\Pharmacy\Application\Command\MarkPickupNotCollected;
use App\Domains\Pharmacy\Application\Command\RecordPickup;
use App\Domains\Pharmacy\Application\Command\ReverseDispensing;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrder;
use App\Domains\Pharmacy\Application\Query\GetPickup;
use App\Domains\Pharmacy\Application\Query\SearchPickups;
use App\Domains\Pharmacy\Domain\Exception\DispensingPaymentNotCleared;
use App\Domains\Pharmacy\Contracts\Event\MedicationPickedUp;
use App\Domains\Billing\Contracts\BillingApi;
use App\Domains\Pharmacy\Domain\Exception\DispensingNotReversible;
use App\Domains\Pharmacy\Domain\Exception\IllegalPickupTransition;
use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, order: string, item: string, loc: string}
 */
function pickupSetup(): array
{
    $fixture = pharmacyFixture();
    $billing = \Mockery::mock(BillingApi::class);
    $billing->shouldReceive('dispensingClearedForHandover')->andReturn(true);
    app()->instance(BillingApi::class, $billing);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    grantPermissionsToRole($t, $fixture['role_id'], ['pharmacy.dispensing.reverse']);

    ['prescriptionId' => $rx] = pharmacyAcceptedPrescription($t, $f, $a);
    $order = (string) Str::uuid7();
    dispatchRx($t, $f, new CreatePharmacyOrder(pharmacyOrderId: $order, actorId: $a, prescriptionId: $rx));
    dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $order, actorId: $a));
    ['itemId' => $item, 'locationId' => $loc] = pharmacyStockedItem($t, $f, '100');

    return ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc];
}

function dispenseOne(string $t, string $f, string $a, string $order, string $item, string $loc, string $qty = '10'): string
{
    $id = (string) Str::uuid7();
    dispatchRx($t, $f, new Dispense(dispensingId: $id, actorId: $a, pharmacyOrderId: $order, itemId: $item, locationId: $loc, quantity: $qty));

    return $id;
}

function rxPickupFor(string $t, string $dispensingId): object
{
    return Tenancy::run($t, fn () => DB::table('rx_pickups')->where('dispensing_id', $dispensingId)->first());
}

test('Dispense auto-creates a READY_FOR_PICKUP pickup in the same transaction', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = pickupSetup();

    $d = dispenseOne($t, $f, $a, $order, $item, $loc);

    $p = rxPickupFor($t, $d);
    expect($p)->not->toBeNull()
        ->and($p->status)->toBe(Pickup::STATUS_READY_FOR_PICKUP)
        ->and($p->pickup_number)->toStartWith('PKP-')
        ->and((string) $p->pharmacy_order_id)->toBe($order)
        ->and($p->recipient_type)->toBeNull()
        ->and($p->collected_at)->toBeNull();

    expect(queryRx($t, $f, new SearchPickups(facilityId: $f, status: 'READY_FOR_PICKUP')))->toHaveCount(1);
});

test('RecordPickup refuses handover while the dispensing bill is unpaid or unconfirmed', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = pickupSetup();
    $d = dispenseOne($t, $f, $a, $order, $item, $loc);

    $billing = \Mockery::mock(BillingApi::class);
    $billing->shouldReceive('dispensingClearedForHandover')->with($d)->andReturn(false);
    app()->instance(BillingApi::class, $billing);

    expect(fn () => dispatchRx($t, $f, new RecordPickup(
        actorId: $a,
        pharmacyOrderId: $order,
        dispensingId: $d,
        recipientType: 'PATIENT',
    )))->toThrow(DispensingPaymentNotCleared::class);

    expect(rxPickupFor($t, $d)->status)->toBe(Pickup::STATUS_READY_FOR_PICKUP);
});

test('RecordPickup → PICKED_UP with recipient + attestation + MedicationPickedUp (no recipient name on the wire)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = pickupSetup();
    $d = dispenseOne($t, $f, $a, $order, $item, $loc);

    dispatchRx($t, $f, new RecordPickup(
        actorId: $a, pharmacyOrderId: $order, dispensingId: $d,
        recipientType: 'authorized_rep', recipientName: 'Jane Doe (sister)', verificationNote: 'checked national ID + the RXO number',
    ));

    $p = rxPickupFor($t, $d);
    expect($p->status)->toBe(Pickup::STATUS_PICKED_UP)
        ->and($p->recipient_type)->toBe('AUTHORIZED_REP')
        ->and($p->recipient_name)->toBe('Jane Doe (sister)')
        ->and((string) $p->collected_by_user_id)->toBe($a)
        ->and($p->collected_at)->not->toBeNull();

    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', MedicationPickedUp::class)->first());
    $payload = json_decode((string) $event->payload, true);
    expect($payload['recipientType'])->toBe('AUTHORIZED_REP')
        ->and($payload)->not->toHaveKey('recipientName')
        ->and($payload)->not->toHaveKey('verificationNote');

    // PICKED_UP is terminal
    expect(fn () => dispatchRx($t, $f, new RecordPickup(actorId: $a, pharmacyOrderId: $order, dispensingId: $d, recipientType: 'PATIENT')))
        ->toThrow(IllegalPickupTransition::class);
});

test('NOT_COLLECTED then a late RecordPickup → PICKED_UP; CancelPickup is a separate terminal', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = pickupSetup();

    $d1 = dispenseOne($t, $f, $a, $order, $item, $loc);
    dispatchRx($t, $f, new MarkPickupNotCollected(actorId: $a, pharmacyOrderId: $order, dispensingId: $d1, reasonCode: 'PATIENT_NO_SHOW'));
    expect(rxPickupFor($t, $d1)->status)->toBe(Pickup::STATUS_NOT_COLLECTED);

    dispatchRx($t, $f, new RecordPickup(actorId: $a, pharmacyOrderId: $order, dispensingId: $d1, recipientType: 'PATIENT'));
    expect(rxPickupFor($t, $d1)->status)->toBe(Pickup::STATUS_PICKED_UP);

    $d2 = dispenseOne($t, $f, $a, $order, $item, $loc);
    dispatchRx($t, $f, new CancelPickup(actorId: $a, pharmacyOrderId: $order, dispensingId: $d2, reasonCode: 'DUPLICATE'));
    expect(rxPickupFor($t, $d2)->status)->toBe(Pickup::STATUS_CANCELLED);

    // no domain event for not-collected / cancelled
    $events = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', MedicationPickedUp::class)->count());
    expect($events)->toBe(1); // only the late collection of d1
});

test('ADR-186 D-E: a PICKED_UP dispensing cannot undergo a stock-returning ReverseDispensing', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = pickupSetup();
    $d = dispenseOne($t, $f, $a, $order, $item, $loc, '10');

    dispatchRx($t, $f, new RecordPickup(actorId: $a, pharmacyOrderId: $order, dispensingId: $d, recipientType: 'PATIENT'));

    $onHandBefore = Tenancy::run($t, fn () => (float) DB::table('inv_stock_balances')->where('item_id', $item)->value('on_hand_qty'));

    expect(fn () => dispatchRx($t, $f, new ReverseDispensing(
        reversalId: (string) Str::uuid7(), actorId: $a, pharmacyOrderId: $order, dispensingId: $d, reasonCode: 'DISPENSING_ERROR',
    )))->toThrow(DispensingNotReversible::class);

    // nothing moved
    $onHandAfter = Tenancy::run($t, fn () => (float) DB::table('inv_stock_balances')->where('item_id', $item)->value('on_hand_qty'));
    expect($onHandAfter)->toBe($onHandBefore);
    expect(Tenancy::run($t, fn () => DB::table('rx_dispensing_reversals')->where('dispensing_id', $d)->count()))->toBe(0);
});

test('ADR-186 D-E: reversing a NOT-yet-picked-up dispensing proceeds and CANCELs the pickup', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = pickupSetup();
    $d = dispenseOne($t, $f, $a, $order, $item, $loc, '10');

    dispatchRx($t, $f, new ReverseDispensing(
        reversalId: (string) Str::uuid7(), actorId: $a, pharmacyOrderId: $order, dispensingId: $d, reasonCode: 'WRONG_ITEM',
    ));

    $p = rxPickupFor($t, $d);
    expect($p->status)->toBe(Pickup::STATUS_CANCELLED)
        ->and($p->outcome_reason_code)->toBe('DISPENSING_REVERSED');

    $onHand = Tenancy::run($t, fn () => (float) DB::table('inv_stock_balances')->where('item_id', $item)->value('on_hand_qty'));
    expect($onHand)->toBe(100.0);
});

test('GetPickup returns the record; a pharmacist without pharmacy.pickup.record is denied', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = pickupSetup();
    $d = dispenseOne($t, $f, $a, $order, $item, $loc);
    $pickupId = (string) rxPickupFor($t, $d)->id;

    expect(queryRx($t, $f, new GetPickup($pickupId))->status)->toBe(Pickup::STATUS_READY_FOR_PICKUP);

    $stranger = pharmacyUserWithKeys($t, $f, ['pharmacy.access', 'pharmacy.dispensing.record']);
    test()->actingAs($stranger);
    expect(fn () => dispatchRx($t, $f, new RecordPickup(
        actorId: (string) $stranger->id, pharmacyOrderId: $order, dispensingId: $d, recipientType: 'PATIENT',
    )))->toThrow(AuthorizationException::class);
});

test('rx_pickups is tenant-isolated (FORCE RLS) and MUTABLE (no append-only policy)', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'rx_pickups')->pluck('policyname')->all();
    expect($policies)->toContain('tenant_isolation')
        ->and($policies)->not->toContain('rx_pickups_no_update')
        ->and($policies)->not->toContain('rx_pickups_no_delete');
});
