<?php

declare(strict_types=1);

use App\Domains\Inventory\Contracts\Event\StockConsumptionReversed;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\Dispense;
use App\Domains\Pharmacy\Application\Command\ReverseDispensing;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrder;
use App\Domains\Pharmacy\Application\Query\GetDispensing;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensingReversed;
use App\Domains\Pharmacy\Domain\Exception\DispensingAlreadyReversed;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $rxOverrides
 * @return array{t: string, f: string, a: string, order: string, item: string, loc: string}
 */
function reversalSetup(array $rxOverrides = []): array
{
    $fixture = pharmacyFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;
    grantPermissionsToRole($t, $fixture['role_id'], ['pharmacy.dispensing.reverse']);

    ['prescriptionId' => $rx] = pharmacyAcceptedPrescription($t, $f, $a, $rxOverrides);

    $order = (string) Str::uuid7();
    dispatchRx($t, $f, new CreatePharmacyOrder(pharmacyOrderId: $order, actorId: $a, prescriptionId: $rx));
    dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $order, actorId: $a));

    ['itemId' => $item, 'locationId' => $loc] = pharmacyStockedItem($t, $f, '100');

    return ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc];
}

function dispenseFor(string $t, string $f, string $a, string $order, string $item, string $loc, string $qty): string
{
    $id = (string) Str::uuid7();
    dispatchRx($t, $f, new Dispense(dispensingId: $id, actorId: $a, pharmacyOrderId: $order, itemId: $item, locationId: $loc, quantity: $qty));

    return $id;
}

function reverse(string $t, string $f, string $a, string $order, string $dispensing, string $reason = 'DISPENSING_ERROR'): string
{
    $id = (string) Str::uuid7();
    dispatchRx($t, $f, new ReverseDispensing(
        reversalId: $id, actorId: $a, pharmacyOrderId: $order, dispensingId: $dispensing, reasonCode: $reason,
    ));

    return $id;
}

test('ReverseDispensing: a COMPLETED dispensing → a DispensingReversal + a compensating IN movement + on_hand restored + the order rollup back + both events', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = reversalSetup();

    $dispensing = dispenseFor($t, $f, $a, $order, $item, $loc, '30');
    expect(rxPharmacyOrder($t, $order)->status)->toBe(PharmacyOrder::STATUS_PARTIALLY_DISPENSED);

    $balanceBefore = Tenancy::run($t, fn () => DB::table('inv_stock_balances')->where('item_id', $item)->first());
    expect((float) $balanceBefore->on_hand_qty)->toBe(70.0);

    $reversalId = reverse($t, $f, $a, $order, $dispensing, 'WRONG_DOSE');

    $rev = Tenancy::run($t, fn () => DB::table('rx_dispensing_reversals')->where('id', $reversalId)->first());
    expect($rev->dispensing_id)->toBe($dispensing)
        ->and((float) $rev->quantity)->toBe(30.0)
        ->and($rev->reason_code)->toBe('WRONG_DOSE')
        ->and($rev->reversal_number)->toStartWith('DRV-')
        ->and($rev->status)->toBe('COMPLETED');

    // the compensating IN movement
    $mv = Tenancy::run($t, fn () => DB::table('inv_stock_movements')->where('id', $rev->reversal_movement_id)->first());
    expect($mv->movement_type)->toBe('CONSUMPTION_REVERSAL')
        ->and($mv->direction)->toBe('IN')
        ->and((string) $mv->reverses_movement_id)->not->toBeNull()
        ->and($mv->source_type)->toBe('PHARMACY_REVERSAL');

    // stock restored; order back to VALIDATED (the only dispensing was reversed)
    $balanceAfter = Tenancy::run($t, fn () => DB::table('inv_stock_balances')->where('item_id', $item)->first());
    expect((float) $balanceAfter->on_hand_qty)->toBe(100.0);
    expect(rxPharmacyOrder($t, $order)->status)->toBe(PharmacyOrder::STATUS_VALIDATED);

    // the dispensing summary shows the reversal (the original row is preserved)
    $summary = queryRx($t, $f, new GetDispensing($dispensing));
    expect($summary->reversalId)->toBe($reversalId)
        ->and($summary->reversalReasonCode)->toBe('WRONG_DOSE')
        ->and($summary->status)->toBe('COMPLETED'); // NOT mutated

    // events
    $pharmEvent = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', MedicationDispensingReversed::class)->first());
    expect(json_decode((string) $pharmEvent->payload, true)['reasonCode'])->toBe('WRONG_DOSE');
    $invEvent = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', StockConsumptionReversed::class)->first());
    expect($invEvent)->not->toBeNull();

    test()->assertDatabaseHas('shared_audit_log', ['tenant_id' => $t, 'action' => 'pharmacy.dispensing_reversed', 'reason' => null]);
    test()->assertDatabaseHas('shared_audit_log', ['tenant_id' => $t, 'action' => 'inventory.stock_consumption_reversed']);
});

test('§112 headroom is freed: reverse one of two dispensings → PARTIALLY_DISPENSED; re-dispense up to the frozen ceiling', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] =
        reversalSetup(['quantityValue' => '30', 'quantityUnit' => 'tablet']);

    $d1 = dispenseFor($t, $f, $a, $order, $item, $loc, '20');
    $d2 = dispenseFor($t, $f, $a, $order, $item, $loc, '10'); // order now DISPENSED
    expect(rxPharmacyOrder($t, $order)->status)->toBe(PharmacyOrder::STATUS_DISPENSED);

    reverse($t, $f, $a, $order, $d2); // net 20/30
    expect(rxPharmacyOrder($t, $order)->status)->toBe(PharmacyOrder::STATUS_PARTIALLY_DISPENSED);

    // headroom is back — re-dispense 10 → DISPENSED again
    dispenseFor($t, $f, $a, $order, $item, $loc, '10');
    expect(rxPharmacyOrder($t, $order)->status)->toBe(PharmacyOrder::STATUS_DISPENSED);
});

test('a dispensing can be reversed only once', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = reversalSetup();
    $d = dispenseFor($t, $f, $a, $order, $item, $loc, '10');

    reverse($t, $f, $a, $order, $d);
    expect(fn () => reverse($t, $f, $a, $order, $d))->toThrow(DispensingAlreadyReversed::class);
});

test('a pharmacist with pharmacy.dispensing.record but NOT .reverse is denied', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = reversalSetup();
    $d = dispenseFor($t, $f, $a, $order, $item, $loc, '10');

    $stranger = pharmacyUserWithKeys($t, $f, ['pharmacy.access', 'pharmacy.dispensing.record']);
    test()->actingAs($stranger);
    expect(fn () => dispatchRx($t, $f, new ReverseDispensing(
        reversalId: (string) Str::uuid7(), actorId: (string) $stranger->id,
        pharmacyOrderId: $order, dispensingId: $d, reasonCode: 'DISPENSING_ERROR',
    )))->toThrow(AuthorizationException::class);
});

test('rx_dispensing_reversals is tenant-isolated (FORCE RLS) and append-only', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'rx_dispensing_reversals')->pluck('policyname')->all();
    expect($policies)->toContain('tenant_isolation')
        ->and($policies)->toContain('rx_dispensing_reversals_no_update')
        ->and($policies)->toContain('rx_dispensing_reversals_no_delete');
});
