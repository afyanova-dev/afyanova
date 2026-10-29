<?php

declare(strict_types=1);

use App\Domains\Patient\Domain\Model\Patient;
use App\Domains\Radiology\Application\Command\AcceptImagingOrder;
use App\Domains\Radiology\Application\Command\CancelImagingOrder;
use App\Domains\Radiology\Application\Command\CreateImagingOrder;
use App\Domains\Radiology\Application\Command\RejectImagingOrder;
use App\Domains\Radiology\Application\Command\SubmitImagingOrder;
use App\Domains\Radiology\Application\Query\GetImagingOrder;
use App\Domains\Radiology\Application\Query\GetPatientImagingOrders;
use App\Domains\Radiology\Application\Query\SearchImagingOrders;
use App\Domains\Radiology\Contracts\Event\ImagingOrderAccepted;
use App\Domains\Radiology\Contracts\Event\ImagingOrderCreated;
use App\Domains\Radiology\Contracts\Event\ImagingOrderRejected;
use App\Domains\Radiology\Contracts\Event\ImagingOrderSubmitted;
use App\Domains\Radiology\Domain\Exception\EncounterNotEligibleForImagingOrder;
use App\Domains\Radiology\Domain\Exception\IllegalImagingOrderTransition;
use App\Domains\Radiology\Domain\Exception\ImagingOrderContextMismatch;
use App\Domains\Radiology\Domain\Exception\ImagingOrderHasNoItems;
use App\Domains\Radiology\Domain\Exception\ImagingServiceNotOrderable;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Model\ImagingOrderItem;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchRad(string $tenantId, ?string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('CreateImagingOrder creates a DRAFT order with snapshotted service lines, copies the encounter facility, emits a PHI-free event, audits', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $modality = seedImagingModality($t, ['code' => 'XR', 'name' => 'X-Ray']);
    $service = seedImagingService($t, (string) $modality->id, [
        'code' => 'XR-CHEST', 'name' => 'Chest X-Ray', 'version' => 4, 'body_site' => 'CHEST',
    ]);
    ['encounterId' => $encounterId, 'patientId' => $patientId] = seedActiveRadiologyEncounter($t, $f, (string) $user->id);

    $orderId = (string) Str::uuid7();
    dispatchRad($t, $f, new CreateImagingOrder(
        orderId: $orderId,
        actorId: (string) $user->id,
        patientId: $patientId,
        encounterId: $encounterId,
        serviceIds: [(string) $service->id],
        priority: 'urgent',
        clinicalIndicationText: 'Rule out pneumonia — Jane Doe.',
    ));

    $order = TenantContextScope::run($t, $f, fn () => ImagingOrder::query()->find($orderId));
    expect($order)->not->toBeNull()
        ->and($order->status)->toBe(ImagingOrder::STATUS_DRAFT)
        ->and((string) $order->facility_id)->toBe($f)
        ->and((string) $order->patient_id)->toBe($patientId)
        ->and($order->priority)->toBe('URGENT')
        ->and($order->order_number)->toStartWith('RAD-')
        ->and((int) $order->version)->toBe(0);

    $item = TenantContextScope::run($t, $f, fn () => ImagingOrderItem::query()->where('order_id', $orderId)->first());
    expect($item)->not->toBeNull()
        ->and((string) $item->imaging_service_id)->toBe((string) $service->id)
        ->and($item->service_code)->toBe('XR-CHEST')
        ->and($item->service_name)->toBe('Chest X-Ray')
        ->and((int) $item->service_definition_version)->toBe(4)
        ->and($item->modality_code)->toBe('XR')
        ->and($item->body_site)->toBe('CHEST')
        ->and($item->status)->toBe(ImagingOrderItem::STATUS_ORDERED);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')
        ->where('event_class', ImagingOrderCreated::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->not->toContain('Jane Doe')
        ->and($outbox->payload)->not->toContain('pneumonia')
        ->and($outbox->payload)->toContain($order->order_number);

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $orderId)->where('action', 'radiology.order_created')->first());
    expect($audit)->not->toBeNull();
});

test('an imaging order runs DRAFT -> ORDERED -> ACCEPTED, each step emitting its PHI-free event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $service = seedImagingService($t);
    ['encounterId' => $encounterId, 'patientId' => $patientId] = seedActiveRadiologyEncounter($t, $f, $a);

    $orderId = (string) Str::uuid7();
    dispatchRad($t, $f, new CreateImagingOrder(
        orderId: $orderId, actorId: $a, patientId: $patientId, encounterId: $encounterId,
        serviceIds: [(string) $service->id],
    ));
    dispatchRad($t, $f, new SubmitImagingOrder(orderId: $orderId, actorId: $a));
    dispatchRad($t, $f, new AcceptImagingOrder(orderId: $orderId, actorId: $a));

    $order = TenantContextScope::run($t, $f, fn () => ImagingOrder::query()->find($orderId));
    expect($order->status)->toBe(ImagingOrder::STATUS_ACCEPTED);

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', ImagingOrderSubmitted::class)->count()))->toBe(1)
        ->and(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', ImagingOrderAccepted::class)->count()))->toBe(1);
});

test('rejecting a submitted order stores the reason row-only and keeps it out of the event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $service = seedImagingService($t);
    ['encounterId' => $encounterId, 'patientId' => $patientId] = seedActiveRadiologyEncounter($t, $f, $a);

    $orderId = (string) Str::uuid7();
    dispatchRad($t, $f, new CreateImagingOrder(orderId: $orderId, actorId: $a, patientId: $patientId, encounterId: $encounterId, serviceIds: [(string) $service->id]));
    dispatchRad($t, $f, new SubmitImagingOrder(orderId: $orderId, actorId: $a));
    dispatchRad($t, $f, new RejectImagingOrder(orderId: $orderId, actorId: $a, reason: 'Wrong modality for the question asked.'));

    $order = TenantContextScope::run($t, $f, fn () => ImagingOrder::query()->find($orderId));
    expect($order->status)->toBe(ImagingOrder::STATUS_REJECTED)
        ->and($order->rejection_reason)->toBe('Wrong modality for the question asked.');

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', ImagingOrderRejected::class)->first());
    expect($outbox->payload)->not->toContain('Wrong modality');

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $orderId)->where('action', 'radiology.order_rejected')->value('reason')))->toBe('Wrong modality for the question asked.');
});

test('an accepted order can no longer be cancelled', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['orderId' => $orderId] = seedAcceptedImagingOrder($t, $f, $a);

    expect(fn () => dispatchRad($t, $f, new CancelImagingOrder(orderId: $orderId, actorId: $a)))
        ->toThrow(IllegalImagingOrderTransition::class);
});

test('CreateImagingOrder rejects an empty service list, a mismatched patient, a non-orderable encounter, and a referral-only service', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    expect(fn () => new CreateImagingOrder(orderId: (string) Str::uuid7(), actorId: $a, patientId: (string) Str::uuid7(), encounterId: (string) Str::uuid7(), serviceIds: []))
        ->toThrow(ImagingOrderHasNoItems::class);

    $service = seedImagingService($t);
    ['encounterId' => $encounterId, 'patientId' => $patientId] = seedActiveRadiologyEncounter($t, $f, $a);
    $otherPatientId = TenantContextScope::run($t, null, fn () => (string) Patient::factory()->forTenant($t)->create()->id);

    expect(fn () => dispatchRad($t, $f, new CreateImagingOrder(
        orderId: (string) Str::uuid7(), actorId: $a, patientId: $otherPatientId, encounterId: $encounterId,
        serviceIds: [(string) $service->id],
    )))->toThrow(ImagingOrderContextMismatch::class);

    ['encounterId' => $plannedEncounterId, 'patientId' => $plannedPatientId] =
        seedActiveRadiologyEncounter($t, $f, $a, started: false, planned: true);
    expect(fn () => dispatchRad($t, $f, new CreateImagingOrder(
        orderId: (string) Str::uuid7(), actorId: $a, patientId: $plannedPatientId, encounterId: $plannedEncounterId,
        serviceIds: [(string) $service->id],
    )))->toThrow(EncounterNotEligibleForImagingOrder::class);

    $referralService = seedImagingService($t, (string) $service->modality_id, ['code' => 'MRI-EXT', 'referral_only' => true]);
    expect(fn () => dispatchRad($t, $f, new CreateImagingOrder(
        orderId: (string) Str::uuid7(), actorId: $a, patientId: $patientId, encounterId: $encounterId,
        serviceIds: [(string) $referralService->id],
    )))->toThrow(ImagingServiceNotOrderable::class);
});

test('a suspended parent modality blocks ordering its services', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $modality = seedImagingModality($t, ['operational_status' => 'SUSPENDED']);
    $service = seedImagingService($t, (string) $modality->id);
    ['encounterId' => $encounterId, 'patientId' => $patientId] = seedActiveRadiologyEncounter($t, $f, $a);

    expect(fn () => dispatchRad($t, $f, new CreateImagingOrder(
        orderId: (string) Str::uuid7(), actorId: $a, patientId: $patientId, encounterId: $encounterId,
        serviceIds: [(string) $service->id],
    )))->toThrow(ImagingServiceNotOrderable::class);
});

test('creating an order requires radiology.orders.create; accepting requires radiology.orders.accept', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture(permissions: [
        'radiology.access', 'radiology.orders.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    $service = seedImagingService($t);
    ['encounterId' => $encounterId, 'patientId' => $patientId] = seedActiveRadiologyEncounter($t, $f, $a);

    $orderId = (string) Str::uuid7();
    dispatchRad($t, $f, new CreateImagingOrder(orderId: $orderId, actorId: $a, patientId: $patientId, encounterId: $encounterId, serviceIds: [(string) $service->id]));
    dispatchRad($t, $f, new SubmitImagingOrder(orderId: $orderId, actorId: $a));

    expect(fn () => dispatchRad($t, $f, new AcceptImagingOrder(orderId: $orderId, actorId: $a)))
        ->toThrow(AuthorizationException::class);
});

test('GetImagingOrder, SearchImagingOrders and GetPatientImagingOrders return the order', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['orderId' => $orderId, 'patientId' => $patientId] = seedAcceptedImagingOrder($t, $f, $a);

    $one = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetImagingOrder(orderId: $orderId)));
    expect($one->orderId)->toBe($orderId)->and($one->status)->toBe(ImagingOrder::STATUS_ACCEPTED)
        ->and($one->items)->toHaveCount(1);

    $queue = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new SearchImagingOrders(facilityId: $f)));
    expect($queue)->toHaveCount(1)->and($queue[0]->orderId)->toBe($orderId);

    $forPatient = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetPatientImagingOrders(patientId: $patientId)));
    expect($forPatient)->toHaveCount(1)->and($forPatient[0]->orderId)->toBe($orderId);
});
