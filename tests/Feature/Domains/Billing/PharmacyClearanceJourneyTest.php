<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\MapItemToBillingService;
use App\Domains\Billing\Application\Command\RecordPayment;
use App\Domains\Billing\Application\Command\SetInvoiceCoverage;
use App\Domains\Billing\Application\Listener\OnMedicationDispensed;
use App\Domains\Billing\Application\Listener\OnMedicationDispensingReversed;
use App\Domains\Billing\Application\Query\GetCharge;
use App\Domains\Billing\Application\Query\GetInvoice;
use App\Domains\Billing\Application\Query\GetPendingBillableEvents;
use App\Domains\Billing\Contracts\BillingApi;
use App\Domains\Billing\Domain\Model\Charge;
use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\Dispense;
use App\Domains\Pharmacy\Application\Command\MarkPickupNotCollected;
use App\Domains\Pharmacy\Application\Command\RecordPickup;
use App\Domains\Pharmacy\Application\Command\ReverseDispensing;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrder;
use App\Domains\Pharmacy\Contracts\Event\MedicationDispensed;
use App\Domains\Pharmacy\Contracts\Event\MedicationPickedUp;
use App\Domains\Pharmacy\Domain\Exception\DispensingPaymentNotCleared;
use App\Domains\Pharmacy\Domain\Exception\IllegalPickupTransition;
use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Shared\Events\DispatchListenerJob;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 | PHASE 6 — End-to-end financial clearance and pickup.
 |
 | These tests drive the REAL path (no mocked BillingApi): a stocked Inventory
 | item → a real Pharmacy `Dispense` (which records `MedicationDispensed` on the
 | outbox) → the outbox relay + the real `OnMedicationDispensed` listener →
 | Billing charge capture → dedicated invoice/issue/payment/allocation or
 | confirmed coverage → the real `BillingApi::dispensingClearedForHandover`
 | gate → a real `RecordPickup`.
 |
 | They exist to demonstrate the journey AND the recoverable failure/pending
 | states, per the Phase 6 exit criteria. Existing suites prove the pieces in
 | isolation (`ChargeCaptureTest`, `DispensingHandoverClearanceTest`,
 | `PickupTest`); this file proves they compose against real stock + mappings.
 */

/**
 * A tenant + facility + user holding the pharmacist keys (`pharmacyFixture`)
 * PLUS the Billing cashier/catalog and Insurance keys, so the real BillingApi
 * (never a mock) can capture charges, invoice, take payment, apply coverage,
 * and resolve handover clearance.
 *
 * @return array{t: string, f: string, a: string, role: string}
 */
function clearanceJourneyFixture(): array
{
    $fixture = pharmacyFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;

    grantPermissionsToRole($t, $fixture['role_id'], [
        'pharmacy.dispensing.reverse',
        'billing.access',
        'billing.accounts.manage',
        'billing.catalog.manage',
        'billing.charges.manage',
        'billing.invoices.manage',
        'billing.payments.manage',
        'billing.cash_sessions.manage',
        'insurance.access',
        'insurance.payers.manage',
        'insurance.plans.manage',
        'insurance.members.manage',
        'insurance.coverage.manage',
    ]);

    return ['t' => $t, 'f' => $f, 'a' => $a, 'role' => (string) $fixture['role_id']];
}

/**
 * The real journey start: an accepted prescription, a validated order, a
 * stocked MEDICATION item, and (optionally) an item→service map + a tenant
 * default tariff.
 *
 * @return array{t: string, f: string, a: string, order: string, item: string, loc: string, patient: string, svc: ?string}
 */
function clearanceJourneySetup(bool $mapped = true, bool $priced = true, string $tariff = '500.00'): array
{
    ['t' => $t, 'f' => $f, 'a' => $a] = clearanceJourneyFixture();

    ['prescriptionId' => $rx, 'patientId' => $patient] = pharmacyAcceptedPrescription($t, $f, $a);

    $order = (string) Str::uuid7();
    dispatchRx($t, $f, new CreatePharmacyOrder(pharmacyOrderId: $order, actorId: $a, prescriptionId: $rx));
    dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $order, actorId: $a));

    ['itemId' => $item, 'locationId' => $loc] = pharmacyStockedItem($t, $f, '100');

    $svc = null;
    if ($mapped) {
        $svc = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
        if ($priced) {
            billingSetTariff($t, $f, $a, $svc, null, $tariff, '2020-01-01');
        }
        billingMapItem($t, $f, $a, $item, $svc);
    }

    return ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc, 'patient' => $patient, 'svc' => $svc];
}

/** A real `Dispense` — reserves + consumes stock and records `MedicationDispensed` on the outbox. */
function clearanceJourneyDispense(string $t, string $f, string $a, string $order, string $item, string $loc, string $qty = '10'): string
{
    $id = (string) Str::uuid7();
    dispatchRx($t, $f, new Dispense(dispensingId: $id, actorId: $a, pharmacyOrderId: $order, itemId: $item, locationId: $loc, quantity: $qty));

    return $id;
}

/** Relay the outbox and run the named listeners as a real worker would (only those listeners). */
function clearanceJourneyRelay(string $t, string ...$listenerClasses): void
{
    Queue::fake([DispatchListenerJob::class]);
    test()->artisan('outbox:relay', ['--once' => true])->assertSuccessful();

    collect(Queue::pushed(DispatchListenerJob::class))
        ->filter(fn (DispatchListenerJob $j): bool => in_array($j->listenerClass, $listenerClasses, true))
        ->each(fn (DispatchListenerJob $j) => $j->handle());
}

/** Relay + run the real `OnMedicationDispensed` charge-capture listener(s). */
function clearanceJourneyCapture(string $t): void
{
    clearanceJourneyRelay($t, OnMedicationDispensed::class);
}

function clearanceJourneyBillableEvent(string $t, string $dispensingId): ?object
{
    return Tenancy::run($t, fn () => DB::table('bill_billable_events')->where('source_ref', $dispensingId)->first());
}

function clearanceJourneyChargeCount(string $t, string $dispensingId): int
{
    return Tenancy::run($t, fn () => DB::table('bill_charges')->where('source_ref', $dispensingId)->count());
}

function clearanceJourneyCleared(string $t, string $f, string $dispensingId): bool
{
    return TenantContextScope::run($t, $f, fn (): bool => app(BillingApi::class)->dispensingClearedForHandover($dispensingId));
}

function clearanceJourneyPickup(string $t, string $dispensingId): object
{
    return Tenancy::run($t, fn () => DB::table('rx_pickups')->where('dispensing_id', $dispensingId)->first());
}

function clearanceJourneyRecordPickup(string $t, string $f, string $a, string $order, string $dispensingId): void
{
    dispatchRx($t, $f, new RecordPickup(actorId: $a, pharmacyOrderId: $order, dispensingId: $dispensingId, recipientType: 'PATIENT'));
}

function clearanceJourneyOnHand(string $t, string $item): float
{
    return Tenancy::run($t, fn () => (float) DB::table('inv_stock_balances')->where('item_id', $item)->value('on_hand_qty'));
}

function clearanceJourneyAccount(string $t, string $f, string $patient): string
{
    return (string) Tenancy::run($t, fn () => DB::table('bill_financial_accounts')
        ->where('patient_id', $patient)->where('facility_id', $f)->value('id'));
}

/**
 * Register a payer + plan + member coverage for the patient and set an
 * 80/2000-style rule; returns the member-coverage id for `SetInvoiceCoverage`.
 */
function clearanceJourneyCoverage(string $t, string $f, string $a, string $patient, ?int $percent = 80, string $coPay = '2000.00'): string
{
    $payer = insuranceRegisterPayer($t, $f, $a, 'NHIF', 'GOVERNMENT_PROGRAM');
    $plan = insuranceCreatePlan($t, $f, $a, $payer, 'STD-'.Str::upper(Str::random(4)));
    insuranceRegisterCoverage($t, $f, $a, $patient, $plan);

    if ($percent !== null) {
        insuranceSetCoverageRule($t, $f, $a, $plan, 'MEDICATION', 'COVERED', '2020-01-01', coveragePercent: $percent, coPayAmount: $coPay);
    }

    return (string) Tenancy::run($t, fn () => DB::table('ins_member_coverages')->where('plan_id', $plan)->value('id'));
}

// ── the primary journey ──────────────────────────────────────────────

test('self-pay journey: real stock → dispense → async charge → dedicated invoice → payment → pickup', function (): void {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc, 'patient' => $patient] = clearanceJourneySetup();

    // 1. Dispense real stock. Charge capture rides the outbox — it is NOT synchronous.
    $d = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '10');

    expect(clearanceJourneyPickup($t, $d)->status)->toBe(Pickup::STATUS_READY_FOR_PICKUP)
        ->and(clearanceJourneyOnHand($t, $item))->toBe(90.0)
        ->and(clearanceJourneyChargeCount($t, $d))->toBe(0)
        ->and(clearanceJourneyBillableEvent($t, $d))->toBeNull();

    // Pickup is blocked, but recoverably: the dispensing itself succeeded.
    expect(fn () => clearanceJourneyRecordPickup($t, $f, $a, $order, $d))->toThrow(DispensingPaymentNotCleared::class);
    expect(clearanceJourneyPickup($t, $d)->status)->toBe(Pickup::STATUS_READY_FOR_PICKUP);

    // 2. Relay the outbox → exactly ONE posted charge.
    clearanceJourneyCapture($t);

    $be = clearanceJourneyBillableEvent($t, $d);
    expect($be)->not->toBeNull()
        ->and($be->status)->toBe('CHARGED')
        ->and($be->charge_id)->not->toBeNull()
        ->and(clearanceJourneyChargeCount($t, $d))->toBe(1);

    $chargeId = (string) $be->charge_id;
    expect(queryBilling($t, $f, new GetCharge($chargeId))->amount)->toBe('5000.00') // 500.00 × 10
        ->and(queryBilling($t, $f, new GetCharge($chargeId))->sourceRef)->toBe($d);

    $accountId = clearanceJourneyAccount($t, $f, $patient);
    expect(clearanceJourneyCleared($t, $f, $d))->toBeFalse(); // no invoice yet

    // 3. Dedicated one-charge invoice → issue → still blocked while unpaid.
    $invoiceId = billingCreateInvoice($t, $f, $a, $accountId, [$chargeId]);
    expect(clearanceJourneyCleared($t, $f, $d))->toBeFalse();

    billingIssueInvoice($t, $f, $a, $invoiceId);
    expect(clearanceJourneyCleared($t, $f, $d))->toBeFalse();
    expect(fn () => clearanceJourneyRecordPickup($t, $f, $a, $order, $d))->toThrow(DispensingPaymentNotCleared::class);

    // 4. Pay in full → cleared → pickup recorded + `MedicationPickedUp` emitted.
    $paymentId = billingRecordPayment($t, $f, $a, $accountId, '5000.00');
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00');

    expect(clearanceJourneyCleared($t, $f, $d))->toBeTrue();

    clearanceJourneyRecordPickup($t, $f, $a, $order, $d);
    expect(clearanceJourneyPickup($t, $d)->status)->toBe(Pickup::STATUS_PICKED_UP);

    $pickedUp = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', MedicationPickedUp::class)->count());
    expect($pickedUp)->toBe(1);
});

test('a re-delivered MedicationDispensed for the same dispensing never double-charges', function (): void {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = clearanceJourneySetup();

    $d = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '3');
    clearanceJourneyCapture($t);
    expect(clearanceJourneyChargeCount($t, $d))->toBe(1);

    // Force outbox re-delivery of the SAME event (the at-least-once contract).
    $rowId = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', MedicationDispensed::class)->value('id'));
    Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('id', $rowId)->update(['published_at' => null]));
    clearanceJourneyCapture($t);

    expect(clearanceJourneyChargeCount($t, $d))->toBe(1)
        ->and(Tenancy::run($t, fn () => DB::table('bill_billable_events')->where('source_ref', $d)->count()))->toBe(1);
});

// ── pending exceptions are visible + recoverable ─────────────────────

test('an unmapped item holds a visible ITEM_UNMAPPED pending event; mapping + tariff recovers the journey', function (): void {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = clearanceJourneySetup(mapped: false);

    $d = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '4');
    clearanceJourneyCapture($t);

    $be = clearanceJourneyBillableEvent($t, $d);
    expect($be->status)->toBe('PENDING')
        ->and($be->pending_reason)->toBe('ITEM_UNMAPPED')
        ->and($be->charge_id)->toBeNull()
        ->and(clearanceJourneyChargeCount($t, $d))->toBe(0);

    // The exception is visible to staff and the dispensing is not lost.
    expect(queryBilling($t, $f, new GetPendingBillableEvents))->toHaveCount(1);
    expect(clearanceJourneyCleared($t, $f, $d))->toBeFalse();

    // Recover: publish a tariff, then map the item — the mapping sweeps ITEM_UNMAPPED.
    $svc = billingAddService($t, $f, $a, 'MED-'.Str::upper(Str::random(4)), 'MEDICATION');
    billingSetTariff($t, $f, $a, $svc, null, '250.00', '2020-01-01');
    billingMapItem($t, $f, $a, $item, $svc);

    $be = clearanceJourneyBillableEvent($t, $d);
    expect($be->status)->toBe('CHARGED')
        ->and(queryBilling($t, $f, new GetPendingBillableEvents))->toHaveCount(0)
        ->and(queryBilling($t, $f, new GetCharge((string) $be->charge_id))->amount)->toBe('1000.00'); // 250 × 4
});

// ── the dedicated-invoice requirement ────────────────────────────────

test('clearance requires a dedicated one-charge invoice: a shared paid invoice does not clear a dispensing', function (): void {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc, 'patient' => $patient] = clearanceJourneySetup();

    $d1 = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '5');
    $d2 = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '5');
    clearanceJourneyCapture($t);

    $charge1 = (string) clearanceJourneyBillableEvent($t, $d1)->charge_id;
    $charge2 = (string) clearanceJourneyBillableEvent($t, $d2)->charge_id;
    expect($charge1)->not->toBe($charge2);

    $accountId = clearanceJourneyAccount($t, $f, $patient);
    $invoiceId = billingCreateInvoice($t, $f, $a, $accountId, [$charge1, $charge2]);
    billingIssueInvoice($t, $f, $a, $invoiceId);
    $paymentId = billingRecordPayment($t, $f, $a, $accountId, '5000.00'); // 2500 + 2500
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00');

    // Fully paid, but the invoice carries two active lines → neither dispensing
    // is provably settled, so both remain blocked (allocation is invoice-level).
    expect(queryBilling($t, $f, new GetInvoice($invoiceId))->paymentStatus)->toBe('PAID')
        ->and(clearanceJourneyCleared($t, $f, $d1))->toBeFalse()
        ->and(clearanceJourneyCleared($t, $f, $d2))->toBeFalse();

    expect(fn () => clearanceJourneyRecordPickup($t, $f, $a, $order, $d1))->toThrow(DispensingPaymentNotCleared::class);
});

// ── insurance clearance ──────────────────────────────────────────────

test('insurance clearance: a CONFIRMED partial coverage clears once the patient pays their share', function (): void {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc, 'patient' => $patient] = clearanceJourneySetup();

    $d = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '10'); // 5000
    clearanceJourneyCapture($t);

    $chargeId = (string) clearanceJourneyBillableEvent($t, $d)->charge_id;
    $accountId = clearanceJourneyAccount($t, $f, $patient);
    $invoiceId = billingCreateInvoice($t, $f, $a, $accountId, [$chargeId]);

    $mc = clearanceJourneyCoverage($t, $f, $a, $patient, 80, '2000.00');
    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoiceId, actorId: $a, memberCoverageId: $mc));

    $draft = queryBilling($t, $f, new GetInvoice($invoiceId));
    expect($draft->coverageStatus)->toBe('PARTIALLY_COVERED')
        ->and($draft->coverageConfidence)->toBe('CONFIRMED');

    billingIssueInvoice($t, $f, $a, $invoiceId);

    // The payer side is not settled synchronously, so the patient share alone
    // authorises handover under the current policy.
    expect(clearanceJourneyCleared($t, $f, $d))->toBeFalse();

    $patientShare = $draft->patientResponsibility;
    expect($patientShare)->not->toBe('0.00');

    $paymentId = billingRecordPayment($t, $f, $a, $accountId, $patientShare);
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, $patientShare);

    expect(clearanceJourneyCleared($t, $f, $d))->toBeTrue();
});

test('insurance coverage that is UNKNOWN never clears a dispensing, even fully paid', function (): void {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc, 'patient' => $patient] = clearanceJourneySetup();

    $d = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '10');
    clearanceJourneyCapture($t);

    $chargeId = (string) clearanceJourneyBillableEvent($t, $d)->charge_id;
    $accountId = clearanceJourneyAccount($t, $f, $patient);
    $invoiceId = billingCreateInvoice($t, $f, $a, $accountId, [$chargeId]);

    // A member coverage with NO matching rule → INSURANCE but UNKNOWN confidence.
    $mc = clearanceJourneyCoverage($t, $f, $a, $patient, null);
    dispatchBilling($t, $f, new SetInvoiceCoverage(invoiceId: $invoiceId, actorId: $a, memberCoverageId: $mc));

    expect(queryBilling($t, $f, new GetInvoice($invoiceId))->coverageConfidence)->toBe('UNKNOWN');
    billingIssueInvoice($t, $f, $a, $invoiceId);

    $paymentId = billingRecordPayment($t, $f, $a, $accountId, '5000.00');
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00');

    expect(clearanceJourneyCleared($t, $f, $d))->toBeFalse();
    expect(fn () => clearanceJourneyRecordPickup($t, $f, $a, $order, $d))->toThrow(DispensingPaymentNotCleared::class);
});

// ── reversal + late pickup interaction ───────────────────────────────

test('reversing an un-picked-up dispensing cancels the pickup and nets its charge to zero', function (): void {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc] = clearanceJourneySetup();

    $d = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '10');
    clearanceJourneyCapture($t);
    $chargeId = (string) clearanceJourneyBillableEvent($t, $d)->charge_id;

    // Reverse BEFORE pickup — the error-correction path.
    dispatchRx($t, $f, new ReverseDispensing(
        reversalId: (string) Str::uuid7(), actorId: $a, pharmacyOrderId: $order, dispensingId: $d, reasonCode: 'WRONG_ITEM',
    ));

    expect(clearanceJourneyPickup($t, $d)->status)->toBe(Pickup::STATUS_CANCELLED)
        ->and(clearanceJourneyOnHand($t, $item))->toBe(100.0); // the exact stock returned

    // Relay the reversal → the charge is reversed (never silently left posted).
    clearanceJourneyRelay($t, OnMedicationDispensingReversed::class);

    expect(clearanceJourneyBillableEvent($t, $d)->status)->toBe('REVERSED')
        ->and(queryBilling($t, $f, new GetCharge($chargeId))->status)->toBe(Charge::STATUS_REVERSED)
        ->and(clearanceJourneyCleared($t, $f, $d))->toBeFalse();

    // A cancelled pickup cannot be handed over.
    expect(fn () => clearanceJourneyRecordPickup($t, $f, $a, $order, $d))->toThrow(IllegalPickupTransition::class);
});

test('late pickup: a returning no-show is handed over once the bill is settled', function (): void {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc, 'patient' => $patient] = clearanceJourneySetup();

    // The patient does not collect the prepared medicine.
    $d = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '10');
    dispatchRx($t, $f, new MarkPickupNotCollected(actorId: $a, pharmacyOrderId: $order, dispensingId: $d, reasonCode: 'PATIENT_NO_SHOW'));
    expect(clearanceJourneyPickup($t, $d)->status)->toBe(Pickup::STATUS_NOT_COLLECTED);

    // The bill is settled later, after the no-show.
    clearanceJourneyCapture($t);
    $chargeId = (string) clearanceJourneyBillableEvent($t, $d)->charge_id;
    $accountId = clearanceJourneyAccount($t, $f, $patient);
    $invoiceId = billingCreateInvoice($t, $f, $a, $accountId, [$chargeId]);
    billingIssueInvoice($t, $f, $a, $invoiceId);
    $paymentId = billingRecordPayment($t, $f, $a, $accountId, '5000.00');
    billingAllocatePayment($t, $f, $a, $paymentId, $invoiceId, '5000.00');

    expect(clearanceJourneyCleared($t, $f, $d))->toBeTrue();

    // The returning patient is handed over (NOT_COLLECTED is not terminal).
    clearanceJourneyRecordPickup($t, $f, $a, $order, $d);
    expect(clearanceJourneyPickup($t, $d)->status)->toBe(Pickup::STATUS_PICKED_UP);
});

// ── role boundaries ──────────────────────────────────────────────────

test('role boundaries: pickup needs pharmacy.pickup.record, payment needs billing.payments.manage, mapping needs billing.catalog.manage', function (): void {
    ['t' => $t, 'f' => $f, 'a' => $a, 'order' => $order, 'item' => $item, 'loc' => $loc, 'patient' => $patient, 'svc' => $svc] = clearanceJourneySetup();

    $d = clearanceJourneyDispense($t, $f, $a, $order, $item, $loc, '10');
    clearanceJourneyCapture($t);
    $chargeId = (string) clearanceJourneyBillableEvent($t, $d)->charge_id;
    $accountId = clearanceJourneyAccount($t, $f, $patient);
    $invoiceId = billingCreateInvoice($t, $f, $a, $accountId, [$chargeId]);
    billingIssueInvoice($t, $f, $a, $invoiceId);

    // A pharmacy-only user (billing.access but no payments key) cannot take money.
    $pharmacyOnly = pharmacyUserWithKeys($t, $f, ['pharmacy.access', 'billing.access']);
    test()->actingAs($pharmacyOnly);
    expect(fn () => dispatchBilling($t, $f, new RecordPayment(
        paymentId: (string) Str::uuid7(), actorId: (string) $pharmacyOnly->id, financialAccountId: $accountId,
        amount: '5000.00', method: 'CASH', paymentReference: null,
    )))->toThrow(AuthorizationException::class);

    // A billing-only user cannot record a pickup (no pharmacy.pickup.record).
    $billingOnly = billingUserWithKeys($t, $f, ['billing.access']);
    test()->actingAs($billingOnly);
    expect(fn () => clearanceJourneyRecordPickup($t, $f, (string) $billingOnly->id, $order, $d))
        ->toThrow(AuthorizationException::class);

    // A billing user without billing.catalog.manage cannot map an item.
    $noCatalog = billingUserWithKeys($t, $f, ['billing.access', 'billing.charges.manage']);
    test()->actingAs($noCatalog);
    expect(fn () => dispatchBilling($t, $f, new MapItemToBillingService(
        mapId: (string) Str::uuid7(), actorId: (string) $noCatalog->id, invItemId: $item, billingServiceId: (string) $svc,
    )))->toThrow(AuthorizationException::class);
});
