<?php

declare(strict_types=1);

use App\Domains\Clinical\Application\Command\CorrectPrescribingDecision;
use App\Domains\Clinical\Application\Command\DiscontinuePrescribingDecision;
use App\Domains\Clinical\Domain\ValueObject\MedicationSubstance;
use App\Domains\Inventory\Domain\Exception\StockBatchExpired;
use App\Domains\Inventory\Domain\Model\Batch;
use App\Domains\Inventory\Domain\Model\Item;
use App\Domains\Inventory\Domain\Model\StockBalance;
use App\Domains\Inventory\Domain\Model\StockLocation;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\Dispense;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrder;
use App\Domains\Pharmacy\Application\Query\GetDispensing;
use App\Domains\Pharmacy\Application\Query\GetPharmacyOrderDispensings;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensed;
use App\Domains\Pharmacy\Domain\Exception\DispensingExceedsAuthorizedQuantity;
use App\Domains\Pharmacy\Domain\Exception\DispensingFacilityMismatch;
use App\Domains\Pharmacy\Domain\Exception\DispensingItemNotInCatalog;
use App\Domains\Pharmacy\Domain\Exception\DispensingNotPermitted;
use App\Domains\Pharmacy\Domain\Exception\DispensingStockUnavailable;
use App\Domains\Pharmacy\Domain\Exception\PharmacyOrderNotReadyForDispensing;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $rxOverrides
 * @return array{t: string, f: string, a: string, order: string, patient: string, pd: string, item: string, loc: string}
 */
function dispenseSetup(array $rxOverrides = []): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = pharmacyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['prescriptionId' => $rx, 'patientId' => $patient, 'prescribingDecisionId' => $pd] =
        pharmacyAcceptedPrescription($t, $f, $a, $rxOverrides);

    $orderId = (string) Str::uuid7();
    dispatchRx($t, $f, new CreatePharmacyOrder(pharmacyOrderId: $orderId, actorId: $a, prescriptionId: $rx));
    dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $orderId, actorId: $a));

    ['itemId' => $item, 'locationId' => $loc] = pharmacyStockedItem($t, $f, '100');

    return ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $orderId, 'patient' => $patient, 'pd' => $pd, 'item' => $item, 'loc' => $loc];
}

function doDispense(string $t, string $f, string $a, string $order, string $item, string $loc, string $qty): string
{
    $id = (string) Str::uuid7();
    dispatchRx($t, $f, new Dispense(
        dispensingId: $id, actorId: $a, pharmacyOrderId: $order, itemId: $item, locationId: $loc, quantity: $qty,
    ));

    return $id;
}

test('Dispense against a VALIDATED order: a COMPLETED Dispensing + a stock OUT movement + a FULFILLED reservation + PARTIALLY_DISPENSED order + MedicationDispensed (PHI-free)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'patient' => $patient, 'item' => $item, 'loc' => $loc] = dispenseSetup();

    $dispensingId = doDispense($t, $f, $a, $order, $item, $loc, '30');

    $d = rxDispensing($t, $dispensingId);
    expect($d->status)->toBe('COMPLETED')
        ->and((string) $d->quantity)->toBe('30.0000')
        ->and((string) $d->pharmacy_order_id)->toBe($order)
        ->and((string) $d->patient_id)->toBe($patient)
        ->and((string) $d->facility_id)->toBe($f)
        ->and($d->dispensing_number)->toStartWith('DSP-')
        ->and($d->authorized_quantity_value)->toBeNull()
        ->and((bool) $d->dispensed_without_ceiling)->toBeTrue()
        ->and($d->financial_clearance_ref)->toBeNull()
        ->and((int) $d->version)->toBe(0);

    // the order rollup advanced
    expect(rxPharmacyOrder($t, $order)->status)->toBe(PharmacyOrder::STATUS_PARTIALLY_DISPENSED);

    // a stock OUT movement + the reservation FULFILLED, reserved_qty back to 0
    $movement = Tenancy::run($t, fn () => DB::table('inv_stock_movements')->where('id', $d->movement_id)->first());
    expect($movement->direction)->toBe('OUT')
        ->and((float) $movement->quantity)->toBe(30.0)
        ->and($movement->source_type)->toBe('PHARMACY');

    $reservation = Tenancy::run($t, fn () => DB::table('inv_stock_reservations')->where('id', $d->reservation_id)->first());
    expect($reservation->status)->toBe('FULFILLED')
        ->and($reservation->consumer_type)->toBe('PHARMACY_ORDER')
        ->and((string) $reservation->consumer_ref)->toBe($order);

    $balance = Tenancy::run($t, fn () => DB::table('inv_stock_balances')
        ->where('item_id', $item)->where('location_id', $loc)->first());
    expect((float) $balance->reserved_qty)->toBe(0.0)
        ->and((float) $balance->on_hand_qty)->toBe(70.0);

    // MedicationDispensed on the outbox — PHI-free
    $event = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', MedicationDispensed::class)->first());
    $payload = json_decode((string) $event->payload, true);
    expect($payload['dispensingId'])->toBe($dispensingId)
        ->and($payload['itemId'])->toBe($item)
        ->and($payload)->not->toHaveKey('medicationName')
        ->and($payload)->not->toHaveKey('patientName')
        ->and($payload)->not->toHaveKey('dose');

    test()->assertDatabaseHas('shared_audit_log', ['tenant_id' => $t, 'action' => 'pharmacy.medication_dispensed']);
    test()->assertDatabaseHas('shared_audit_log', ['tenant_id' => $t, 'action' => 'inventory.stock_reserved']);
    test()->assertDatabaseHas('shared_audit_log', ['tenant_id' => $t, 'action' => 'inventory.stock_consumed']);
});

test('§112 / §113: cumulative dispensing is capped at the C.10 authorised quantity; the last event completes the order', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] =
        dispenseSetup(['quantityValue' => '30', 'quantityUnit' => 'tablet']);

    doDispense($t, $f, $a, $order, $item, $loc, '20');
    expect(rxPharmacyOrder($t, $order)->status)->toBe(PharmacyOrder::STATUS_PARTIALLY_DISPENSED);

    // the frozen ceiling is on every row
    $first = queryRx($t, $f, new GetPharmacyOrderDispensings($order))[0];
    expect($first->authorizedQuantityValue)->toBe('30.0000')
        ->and($first->authorizedQuantityUnit)->toBe('tablet')
        ->and($first->quantityUnit)->toBe('tablet')
        ->and($first->dispensedWithoutCeiling)->toBeFalse();

    // 20 + 15 > 30 → rejected
    expect(fn () => doDispense($t, $f, $a, $order, $item, $loc, '15'))
        ->toThrow(DispensingExceedsAuthorizedQuantity::class);

    // 20 + 10 == 30 → order DISPENSED
    doDispense($t, $f, $a, $order, $item, $loc, '10');
    expect(rxPharmacyOrder($t, $order)->status)->toBe(PharmacyOrder::STATUS_DISPENSED);

    // a fully dispensed order cannot be dispensed again
    expect(fn () => doDispense($t, $f, $a, $order, $item, $loc, '1'))
        ->toThrow(PharmacyOrderNotReadyForDispensing::class);
});

test('dispensing refuses inactive or non-medication catalog items', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order] = dispenseSetup();
    $invalidItems = TenantContextScope::run($t, null, function () use ($t): array {
        return [
            (string) Item::factory()->forTenant($t)->create(['item_type' => 'SUPPLY'])->id,
            (string) Item::factory()->forTenant($t)->inactive()->create()->id,
        ];
    });

    foreach ($invalidItems as $invalidItem) {
        ['locationId' => $location] = pharmacyStockedItem($t, $f, '100', $invalidItem);
        expect(fn () => doDispense($t, $f, $a, $order, $invalidItem, $location, '1'))
            ->toThrow(DispensingItemNotInCatalog::class);
    }

    TenantContextScope::run($t, $f, function () use ($t): void {
        expect(DB::table('rx_dispensings')->where('tenant_id', $t)->count())->toBe(0);
    });
});

test('ADR-183 D-M / HD-3: a prescription with no structured quantity_value dispenses with NO ceiling', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = dispenseSetup();

    doDispense($t, $f, $a, $order, $item, $loc, '40');
    doDispense($t, $f, $a, $order, $item, $loc, '40'); // no cap

    $rows = queryRx($t, $f, new GetPharmacyOrderDispensings($order));
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->dispensedWithoutCeiling)->toBeTrue();
    // stays PARTIALLY_DISPENSED — no ceiling to reach
    expect(rxPharmacyOrder($t, $order)->status)->toBe(PharmacyOrder::STATUS_PARTIALLY_DISPENSED);
});

test('ADR-184 D-D: a DISCONTINUED or SUPERSEDED prescribing decision blocks the first dispensing', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'pd' => $pd, 'item' => $item, 'loc' => $loc] = dispenseSetup();

    dispatchRx($t, $f, new DiscontinuePrescribingDecision(
        prescribingDecisionId: $pd, actorId: $a, reason: 'Therapy changed',
    ));

    expect(fn () => doDispense($t, $f, $a, $order, $item, $loc, '10'))
        ->toThrow(DispensingNotPermitted::class);

    // SUPERSEDED
    ['t' => $t2, 'f' => $f2, 'a' => $a2, 'order' => $order2, 'pd' => $pd2, 'item' => $item2, 'loc' => $loc2] = dispenseSetup();
    dispatchRx($t2, $f2, new CorrectPrescribingDecision(
        newPrescribingDecisionId: (string) Str::uuid7(),
        supersedesPrescribingDecisionId: $pd2,
        actorId: $a2,
        medication: MedicationSubstance::of('Amoxicillin', '500123', 'RxNorm'),
        reason: 'Wrong strength recorded',
    ));

    expect(fn () => doDispense($t2, $f2, $a2, $order2, $item2, $loc2, '10'))
        ->toThrow(DispensingNotPermitted::class);
});

test('a stock location the pharmacist cannot see (different facility, no inventory.access there) is NotFound', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item] = dispenseSetup();

    ['locationId' => $otherLoc] = pharmacyStockedItem($t, (string) Str::uuid7(), '100');

    expect(fn () => doDispense($t, $f, $a, $order, $item, $otherLoc, '10'))
        ->toThrow(NotFound::class);
});

test('§81: a location the pharmacist CAN see but at a different facility than the order is a DispensingFacilityMismatch', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item] = dispenseSetup();

    $otherFacility = (string) Str::uuid7();
    ['locationId' => $otherLoc] = pharmacyStockedItem($t, $otherFacility, '100');
    grantInventoryRole($t, $otherFacility, $a, ['inventory.access']);

    expect(fn () => doDispense($t, $f, $a, $order, $item, $otherLoc, '10'))
        ->toThrow(DispensingFacilityMismatch::class);
});

test('GetDispensing returns the record; a stranger without pharmacy.dispensing.record is denied', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = dispenseSetup();
    $id = doDispense($t, $f, $a, $order, $item, $loc, '10');

    $summary = queryRx($t, $f, new GetDispensing($id));
    expect($summary->dispensingId)->toBe($id)->and($summary->status)->toBe('COMPLETED');

    $stranger = pharmacyUserWithKeys($t, $f, ['pharmacy.access']);
    test()->actingAs($stranger);
    expect(fn () => dispatchRx($t, $f, new Dispense(
        dispensingId: (string) Str::uuid7(), actorId: (string) $stranger->id,
        pharmacyOrderId: $order, itemId: $item, locationId: $loc, quantity: '5',
    )))->toThrow(AuthorizationException::class);
});

test('rx_dispensings is tenant-isolated (FORCE RLS) and append-only (no UPDATE / DELETE)', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'rx_dispensings')->pluck('policyname')->all();
    expect($policies)->toContain('tenant_isolation')
        ->and($policies)->toContain('rx_dispensings_no_update')
        ->and($policies)->toContain('rx_dispensings_no_delete');

    $forced = DB::selectOne("select relforcerowsecurity as f from pg_class where relname = 'rx_dispensings'");
    expect($forced->f)->toBeTrue();
});

test('Phase 4: an item whose only stock is an expired batch reports zero available and is not dispensable', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order] = dispenseSetup();

    [$item, $location] = TenantContextScope::run($t, null, function () use ($t, $f): array {
        $item = Item::factory()->forTenant($t)->create(['item_type' => 'MEDICATION', 'track_batch' => true]);
        $location = StockLocation::factory()->forTenant($t)->forFacility($f)->create();
        $batch = Batch::factory()->forTenant($t)->forItem((string) $item->id)
            ->create(['batch_number' => 'RX-EXPIRED', 'expiry_date' => CarbonImmutable::now()->subDay()->toDateString()]);

        StockBalance::factory()->forTenant($t)->onHand('100')->create([
            'facility_id' => $f, 'item_id' => (string) $item->id,
            'location_id' => (string) $location->id, 'batch_id' => (string) $batch->id,
        ]);

        return [(string) $item->id, (string) $location->id];
    });

    // Expiry exclusion: expired stock never counts as available.
    expect(fn () => dispatchRx($t, $f, new Dispense(
        dispensingId: (string) Str::uuid7(), actorId: $a, pharmacyOrderId: $order,
        itemId: $item, locationId: $location, quantity: '10', batchNumber: 'RX-EXPIRED',
    )))->toThrow(DispensingStockUnavailable::class);

    TenantContextScope::run($t, $f, function () use ($t): void {
        expect(DB::table('rx_dispensings')->where('tenant_id', $t)->count())->toBe(0);
    });
});

test('Phase 4: the consume-time gate blocks an expired batch even when eligible stock exists elsewhere in the facility', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order] = dispenseSetup();

    [$item, $expiredLoc] = TenantContextScope::run($t, null, function () use ($t, $f): array {
        $item = Item::factory()->forTenant($t)->create(['item_type' => 'MEDICATION', 'track_batch' => true]);
        $expiredLoc = StockLocation::factory()->forTenant($t)->forFacility($f)->create();
        $okLoc = StockLocation::factory()->forTenant($t)->forFacility($f)->create();

        $expired = Batch::factory()->forTenant($t)->forItem((string) $item->id)
            ->create(['batch_number' => 'RX-EXPIRED', 'expiry_date' => CarbonImmutable::now()->subDay()->toDateString()]);
        $ok = Batch::factory()->forTenant($t)->forItem((string) $item->id)
            ->create(['batch_number' => 'RX-OK', 'expiry_date' => CarbonImmutable::now()->addYear()->toDateString()]);

        StockBalance::factory()->forTenant($t)->onHand('100')->create([
            'facility_id' => $f, 'item_id' => (string) $item->id, 'location_id' => (string) $expiredLoc->id, 'batch_id' => (string) $expired->id,
        ]);
        StockBalance::factory()->forTenant($t)->onHand('100')->create([
            'facility_id' => $f, 'item_id' => (string) $item->id, 'location_id' => (string) $okLoc->id, 'batch_id' => (string) $ok->id,
        ]);

        return [(string) $item->id, (string) $expiredLoc->id];
    });

    // The facility-wide pre-check passes (the usable batch), but the CHOSEN batch
    // is expired — the authoritative gate fires and the whole Dispense rolls back.
    expect(fn () => dispatchRx($t, $f, new Dispense(
        dispensingId: (string) Str::uuid7(), actorId: $a, pharmacyOrderId: $order,
        itemId: $item, locationId: $expiredLoc, quantity: '10', batchNumber: 'RX-EXPIRED',
    )))->toThrow(StockBatchExpired::class);

    TenantContextScope::run($t, $f, function () use ($t, $item): void {
        expect(DB::table('rx_dispensings')->where('tenant_id', $t)->count())->toBe(0)
            ->and(DB::table('inv_stock_movements')->where('item_id', $item)->count())->toBe(0);
    });
});
