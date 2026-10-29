<?php

declare(strict_types=1);

use App\Domains\Laboratory\Application\Command\AcceptLabOrder;
use App\Domains\Laboratory\Application\Command\CancelLabOrder;
use App\Domains\Laboratory\Application\Command\CreateLabOrder;
use App\Domains\Laboratory\Application\Command\RejectLabOrder;
use App\Domains\Laboratory\Application\Command\SubmitLabOrder;
use App\Domains\Laboratory\Application\Query\GetLabOrder;
use App\Domains\Laboratory\Application\Query\GetPatientLabOrders;
use App\Domains\Laboratory\Application\Query\SearchLabOrders;
use App\Domains\Laboratory\Contracts\Event\LabOrderAccepted;
use App\Domains\Laboratory\Contracts\Event\LabOrderCreated;
use App\Domains\Laboratory\Contracts\Event\LabOrderRejected;
use App\Domains\Laboratory\Contracts\Event\LabOrderSubmitted;
use App\Domains\Laboratory\Domain\Exception\EncounterNotEligibleForLabOrder;
use App\Domains\Laboratory\Domain\Exception\IllegalLabOrderTransition;
use App\Domains\Laboratory\Domain\Exception\LabOrderContextMismatch;
use App\Domains\Laboratory\Domain\Exception\LabOrderHasNoItems;
use App\Domains\Laboratory\Domain\Exception\LabTestNotOrderable;
use App\Domains\Laboratory\Domain\Exception\PatientNotEligibleForLabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchLab(string $tenantId, ?string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('CreateLabOrder creates a DRAFT order with snapshotted item lines, copies the encounter facility, emits a PHI-free event, audits', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $def = seedLabTestDefinition($t, ['code' => 'FBC', 'name' => 'Full Blood Count', 'version' => 3]);
    ['encounterId' => $encounterId, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    $orderId = (string) Str::uuid7();
    dispatchLab($t, $f, new CreateLabOrder(
        orderId: $orderId,
        actorId: (string) $user->id,
        patientId: $patientId,
        encounterId: $encounterId,
        testDefinitionIds: [(string) $def->id],
        priority: 'urgent',
        clinicalIndicationText: 'Suspected anaemia — Hb trend.',
    ));

    $order = TenantContextScope::run($t, $f, fn () => LabOrder::query()->find($orderId));
    expect($order)->not->toBeNull()
        ->and($order->status)->toBe(LabOrder::STATUS_DRAFT)
        ->and((string) $order->facility_id)->toBe($f)
        ->and((string) $order->patient_id)->toBe($patientId)
        ->and($order->priority)->toBe('URGENT')
        ->and($order->order_number)->toStartWith('LAB-')
        ->and((int) $order->version)->toBe(0);

    $item = TenantContextScope::run($t, $f, fn () => LabOrderItem::query()->where('order_id', $orderId)->first());
    expect($item)->not->toBeNull()
        ->and((string) $item->test_definition_id)->toBe((string) $def->id)
        ->and($item->test_code)->toBe('FBC')
        ->and($item->test_name)->toBe('Full Blood Count')
        ->and((int) $item->test_definition_version)->toBe(3)
        ->and($item->status)->toBe(LabOrderItem::STATUS_ORDERED);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', LabOrderCreated::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->not->toContain('anaemia')
        ->and($outbox->payload)->toContain($order->order_number);

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $orderId)->where('action', 'laboratory.order_created')->first());
    expect($audit)->not->toBeNull();
});

test('a lab order runs DRAFT -> ORDERED -> ACCEPTED, each step emitting its PHI-free event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $def = seedLabTestDefinition($t);
    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    $orderId = (string) Str::uuid7();
    dispatchLab($t, $f, new CreateLabOrder(
        orderId: $orderId, actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    ));
    dispatchLab($t, $f, new SubmitLabOrder(orderId: $orderId, actorId: (string) $user->id));
    dispatchLab($t, $f, new AcceptLabOrder(orderId: $orderId, actorId: (string) $user->id));

    $order = TenantContextScope::run($t, $f, fn () => LabOrder::query()->find($orderId));
    expect($order->status)->toBe(LabOrder::STATUS_ACCEPTED)
        ->and((int) $order->version)->toBe(2);

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', LabOrderSubmitted::class)->count()))->toBe(1)
        ->and(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', LabOrderAccepted::class)->count()))->toBe(1);
});

test('RejectLabOrder stores the reason row-only + on the audit row, never in the event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $def = seedLabTestDefinition($t);
    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    $orderId = (string) Str::uuid7();
    dispatchLab($t, $f, new CreateLabOrder(
        orderId: $orderId, actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    ));
    dispatchLab($t, $f, new SubmitLabOrder(orderId: $orderId, actorId: (string) $user->id));
    dispatchLab($t, $f, new RejectLabOrder(orderId: $orderId, actorId: (string) $user->id, reason: 'Specimen requirements not met by ward.'));

    $order = TenantContextScope::run($t, $f, fn () => LabOrder::query()->find($orderId));
    expect($order->status)->toBe(LabOrder::STATUS_REJECTED)
        ->and($order->rejection_reason)->toBe('Specimen requirements not met by ward.');

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', LabOrderRejected::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->payload)->not->toContain('Specimen requirements');

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $orderId)->where('action', 'laboratory.order_rejected')->first());
    expect($audit->reason)->toBe('Specimen requirements not met by ward.');
});

test('CancelLabOrder withdraws a DRAFT or ORDERED order', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $def = seedLabTestDefinition($t);
    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    $orderId = (string) Str::uuid7();
    dispatchLab($t, $f, new CreateLabOrder(
        orderId: $orderId, actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    ));
    dispatchLab($t, $f, new CancelLabOrder(orderId: $orderId, actorId: (string) $user->id, reason: 'Ordered in error.'));

    $order = TenantContextScope::run($t, $f, fn () => LabOrder::query()->find($orderId));
    expect($order->status)->toBe(LabOrder::STATUS_CANCELLED)
        ->and($order->cancellation_reason)->toBe('Ordered in error.');
});

test('CreateLabOrder rejects a DECEASED patient and a patient/encounter mismatch', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $def = seedLabTestDefinition($t);
    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    $deceased = TenantContextScope::run($t, null, fn () => (string) Patient::factory()->forTenant($t)->deceased()->create()->id);
    expect(fn () => dispatchLab($t, $f, new CreateLabOrder(
        orderId: (string) Str::uuid7(), actorId: (string) $user->id, patientId: $deceased, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    )))->toThrow(PatientNotEligibleForLabOrder::class);

    $other = TenantContextScope::run($t, null, fn () => (string) Patient::factory()->forTenant($t)->create()->id);
    expect(fn () => dispatchLab($t, $f, new CreateLabOrder(
        orderId: (string) Str::uuid7(), actorId: (string) $user->id, patientId: $other, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    )))->toThrow(LabOrderContextMismatch::class);
});

test('CreateLabOrder rejects a DISABLED test and a test whose required capability is not enabled', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    $disabled = seedLabTestDefinition($t, ['availability_status' => LabTestDefinition::AVAILABILITY_DISABLED]);
    expect(fn () => dispatchLab($t, $f, new CreateLabOrder(
        orderId: (string) Str::uuid7(), actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $disabled->id],
    )))->toThrow(LabTestNotOrderable::class);

    $needsCapability = seedLabTestDefinition($t, ['required_capability_code' => 'MOLECULAR_DIAGNOSTICS']);
    expect(fn () => dispatchLab($t, $f, new CreateLabOrder(
        orderId: (string) Str::uuid7(), actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $needsCapability->id],
    )))->toThrow(LabTestNotOrderable::class);
});

test('CreateLabOrder rejects an empty test list and a non-orderable encounter', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id, planned: true);
    $def = seedLabTestDefinition($t);

    expect(fn () => new CreateLabOrder(
        orderId: (string) Str::uuid7(), actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [],
    ))->toThrow(LabOrderHasNoItems::class);

    // a PLANNED encounter (never arrived) is not orderable
    expect(fn () => dispatchLab($t, $f, new CreateLabOrder(
        orderId: (string) Str::uuid7(), actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    )))->toThrow(EncounterNotEligibleForLabOrder::class);
});

test('creating requires laboratory.orders.create; accepting requires laboratory.orders.accept', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture(permissions: [
        'laboratory.access', 'laboratory.orders.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $def = seedLabTestDefinition($t);
    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    $orderId = (string) Str::uuid7();
    dispatchLab($t, $f, new CreateLabOrder(
        orderId: $orderId, actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    ));
    dispatchLab($t, $f, new SubmitLabOrder(orderId: $orderId, actorId: (string) $user->id));

    // this user lacks laboratory.orders.accept
    expect(fn () => dispatchLab($t, $f, new AcceptLabOrder(orderId: $orderId, actorId: (string) $user->id)))
        ->toThrow(AuthorizationException::class);
});

test('accepting a DRAFT order is an illegal transition', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $def = seedLabTestDefinition($t);
    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    $orderId = (string) Str::uuid7();
    dispatchLab($t, $f, new CreateLabOrder(
        orderId: $orderId, actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    ));

    expect(fn () => dispatchLab($t, $f, new AcceptLabOrder(orderId: $orderId, actorId: (string) $user->id)))
        ->toThrow(IllegalLabOrderTransition::class);
});

test('GetLabOrder, SearchLabOrders and GetPatientLabOrders return the order with its items', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $def = seedLabTestDefinition($t, ['code' => 'U_E', 'name' => 'Urea & Electrolytes']);
    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);

    $orderId = (string) Str::uuid7();
    dispatchLab($t, $f, new CreateLabOrder(
        orderId: $orderId, actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    ));

    $one = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetLabOrder(orderId: $orderId)));
    expect($one->orderId)->toBe($orderId)
        ->and($one->items)->toHaveCount(1)
        ->and($one->items[0]->testCode)->toBe('U_E');

    $queue = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new SearchLabOrders(facilityId: $f)));
    expect($queue)->toHaveCount(1)->and($queue[0]->orderId)->toBe($orderId);

    $forPatient = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetPatientLabOrders(patientId: $patientId)));
    expect($forPatient)->toHaveCount(1)->and($forPatient[0]->orderId)->toBe($orderId);
});
