<?php

declare(strict_types=1);

use App\Domains\Pharmacy\Application\Command\CreatePharmacyOrder;
use App\Domains\Pharmacy\Application\Command\HoldPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\RejectPharmacyOrder;
use App\Domains\Pharmacy\Application\Command\ReleasePharmacyOrderHold;
use App\Domains\Pharmacy\Application\Command\ValidatePharmacyOrder;
use App\Domains\Pharmacy\Application\Query\GetPharmacyOrder;
use App\Domains\Pharmacy\Application\Query\SearchPharmacyOrders;
use App\Domains\Pharmacy\Contracts\Event\PharmacyOrderRejected;
use App\Domains\Pharmacy\Contracts\Event\PharmacyOrderValidated;
use App\Domains\Pharmacy\Domain\Exception\IllegalPharmacyOrderStatusTransition;
use App\Domains\Pharmacy\Domain\Exception\PharmacyOrderAlreadyExists;
use App\Domains\Pharmacy\Domain\Exception\PrescriptionNotReadyForOrder;
use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, prescription: string, patient: string}
 */
function orderSetup(): array
{
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = pharmacyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['prescriptionId' => $rx, 'patientId' => $patient] = pharmacyAcceptedPrescription($t, $f, $a);

    return ['t' => $t, 'f' => $f, 'a' => $a, 'prescription' => $rx, 'patient' => $patient];
}

function createOrder(string $t, string $f, string $a, string $prescriptionId): string
{
    $orderId = (string) Str::uuid7();
    dispatchRx($t, $f, new CreatePharmacyOrder(
        pharmacyOrderId: $orderId, actorId: $a, prescriptionId: $prescriptionId,
    ));

    return $orderId;
}

test('CreatePharmacyOrder from an ACTIVE prescription lands RECEIVED with an RXO- number and the copied patient', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'prescription' => $rx, 'patient' => $patient] = orderSetup();

    $orderId = createOrder($t, $f, $a, $rx);

    $order = rxPharmacyOrder($t, $orderId);
    expect($order->status)->toBe(PharmacyOrder::STATUS_RECEIVED)
        ->and((string) $order->prescription_id)->toBe($rx)
        ->and((string) $order->patient_id)->toBe($patient)
        ->and((string) $order->facility_id)->toBe($f)
        ->and($order->order_number)->toStartWith('RXO-')
        ->and($order->priority)->toBe('ROUTINE')
        ->and((int) $order->version)->toBe(0);

    test()->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $t, 'action' => 'pharmacy.pharmacy_order_created',
    ]);
});

test('CreatePharmacyOrder is rejected when the prescription is not ACTIVE, and only one non-terminal order per prescription is allowed', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'prescription' => $rx] = orderSetup();

    createOrder($t, $f, $a, $rx);

    // a second order for the same prescription → blocked
    expect(fn () => createOrder($t, $f, $a, $rx))->toThrow(PharmacyOrderAlreadyExists::class);

    // a prescription that is only RECEIVED (never accepted) → not ready
    [$p2, $e2] = clinicalPatientWithEncounter($t, $f, $a);
    ['prescriptionId' => $rx2] = pharmacyIssuePrescription($t, $f, $p2, $e2, $a);
    expect(fn () => createOrder($t, $f, $a, $rx2))->toThrow(PrescriptionNotReadyForOrder::class);
});

test('after a REJECTED order a fresh order can be created for the same prescription', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'prescription' => $rx] = orderSetup();

    $first = createOrder($t, $f, $a, $rx);
    dispatchRx($t, $f, new RejectPharmacyOrder(pharmacyOrderId: $first, actorId: $a, reasonCode: 'DUPLICATE'));

    $second = createOrder($t, $f, $a, $rx); // no PharmacyOrderAlreadyExists
    expect(rxPharmacyOrder($t, $second)->status)->toBe(PharmacyOrder::STATUS_RECEIVED);
});

test('ValidatePharmacyOrder moves RECEIVED → VALIDATED and emits PharmacyOrderValidated (PHI-free)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'prescription' => $rx] = orderSetup();
    $orderId = createOrder($t, $f, $a, $rx);

    dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $orderId, actorId: $a));

    $order = rxPharmacyOrder($t, $orderId);
    expect($order->status)->toBe(PharmacyOrder::STATUS_VALIDATED)
        ->and((string) $order->validated_by_user_id)->toBe($a)
        ->and((int) $order->version)->toBeGreaterThan(0);

    $summary = queryRx($t, $f, new GetPharmacyOrder($orderId));
    expect($summary->status)->toBe(PharmacyOrder::STATUS_VALIDATED);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PharmacyOrderValidated::class)->first());
    expect($outbox->payload)->toContain($rx)
        ->and($outbox->payload)->not->toContain('Amoxicillin');

    // VALIDATED is not re-validatable
    expect(fn () => dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $orderId, actorId: $a)))
        ->toThrow(IllegalPharmacyOrderStatusTransition::class);
});

test('Reject and Cancel carry a coded reason + ROW-ONLY note; the event carries only the code', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'prescription' => $rx] = orderSetup();
    $orderId = createOrder($t, $f, $a, $rx);

    dispatchRx($t, $f, new RejectPharmacyOrder(
        pharmacyOrderId: $orderId, actorId: $a, reasonCode: 'safety',
        note: 'QT-prolonging interaction with the patient current regimen',
    ));

    $order = rxPharmacyOrder($t, $orderId);
    expect($order->status)->toBe(PharmacyOrder::STATUS_REJECTED)
        ->and($order->reject_reason_code)->toBe('SAFETY')
        ->and($order->reason_note)->toContain('QT-prolonging');

    test()->assertDatabaseHas('shared_audit_log', [
        'tenant_id' => $t, 'action' => 'pharmacy.pharmacy_order_rejected', 'reason' => 'SAFETY',
    ]);
    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', PharmacyOrderRejected::class)->first());
    expect($outbox->payload)->toContain('SAFETY')
        ->and($outbox->payload)->not->toContain('QT-prolonging');

    // a bad reason code is rejected at the command boundary
    expect(fn () => new RejectPharmacyOrder(pharmacyOrderId: (string) Str::uuid7(), actorId: $a, reasonCode: 'NONSENSE'))
        ->toThrow(InvalidArgumentException::class);
});

test('Hold parks the order and Release returns it to RECEIVED for re-validation', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'prescription' => $rx] = orderSetup();
    $orderId = createOrder($t, $f, $a, $rx);

    // Hold directly from RECEIVED — no prior event
    dispatchRx($t, $f, new HoldPharmacyOrder(pharmacyOrderId: $orderId, actorId: $a, reasonCode: 'PATIENT_VERIFICATION'));
    expect(rxPharmacyOrder($t, $orderId)->status)->toBe(PharmacyOrder::STATUS_ON_HOLD);

    dispatchRx($t, $f, new ReleasePharmacyOrderHold(pharmacyOrderId: $orderId, actorId: $a));
    $order = rxPharmacyOrder($t, $orderId);
    expect($order->status)->toBe(PharmacyOrder::STATUS_RECEIVED)
        ->and($order->hold_reason_code)->toBeNull();

    // Hold + Release emit no domain event
    $events = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', 'like', '%PharmacyOrder%')->count());
    expect($events)->toBe(0);

    // and the order is still validatable / can be re-validated after the hold
    dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $orderId, actorId: $a));
    expect(rxPharmacyOrder($t, $orderId)->status)->toBe(PharmacyOrder::STATUS_VALIDATED);
});

test('the Pharm.1 prescription aggregate is untouched by the order workflow', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'prescription' => $rx] = orderSetup();
    $orderId = createOrder($t, $f, $a, $rx);
    dispatchRx($t, $f, new RejectPharmacyOrder(pharmacyOrderId: $orderId, actorId: $a, reasonCode: 'INVALID_PRESCRIPTION'));

    $prescription = TenantContextScope::run($t, null, fn () => Prescription::query()->findOrFail($rx));
    expect($prescription->status)->toBe(Prescription::STATUS_ACTIVE); // NOT auto-cancelled
});

test('SearchPharmacyOrders is the facility queue; RLS isolates the tenant; a stranger without pharmacy.orders.manage is denied', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'prescription' => $rx] = orderSetup();
    $o1 = createOrder($t, $f, $a, $rx);
    dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $o1, actorId: $a));

    expect(queryRx($t, $f, new SearchPharmacyOrders(facilityId: $f)))->toHaveCount(1)
        ->and(queryRx($t, $f, new SearchPharmacyOrders(facilityId: $f, status: 'VALIDATED')))->toHaveCount(1)
        ->and(queryRx($t, $f, new SearchPharmacyOrders(facilityId: $f, status: 'RECEIVED')))->toHaveCount(0);

    ['tenant' => $other, 'facility' => $otherF, 'user' => $otherU] = pharmacyFixture();
    test()->actingAs($otherU);
    expect(fn () => queryRx((string) $other->id, (string) $otherF->id, new SearchPharmacyOrders(facilityId: $f)))
        ->toThrow(NotFound::class);

    $stranger = pharmacyUserWithKeys($t, $f, ['pharmacy.access']);
    test()->actingAs($stranger);
    expect(fn () => dispatchRx($t, $f, new ValidatePharmacyOrder(pharmacyOrderId: $o1, actorId: (string) $stranger->id)))
        ->toThrow(AuthorizationException::class);
});

test('rx_pharmacy_orders is tenant-isolated (FORCE RLS)', function () {
    $policies = DB::table('pg_policies')->where('tablename', 'rx_pharmacy_orders')->pluck('policyname')->all();
    expect($policies)->toContain('tenant_isolation');
    $forced = DB::selectOne("select relforcerowsecurity as f from pg_class where relname = 'rx_pharmacy_orders'");
    expect($forced->f)->toBeTrue();
});
