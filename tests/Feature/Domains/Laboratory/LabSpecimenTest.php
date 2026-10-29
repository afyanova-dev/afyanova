<?php

declare(strict_types=1);

use App\Domains\Laboratory\Application\Command\AcceptSpecimen;
use App\Domains\Laboratory\Application\Command\CollectSpecimen;
use App\Domains\Laboratory\Application\Command\CreateLabOrder;
use App\Domains\Laboratory\Application\Command\ReceiveSpecimen;
use App\Domains\Laboratory\Application\Command\RejectSpecimen;
use App\Domains\Laboratory\Application\Command\RequestRecollection;
use App\Domains\Laboratory\Application\Query\GetLabSpecimen;
use App\Domains\Laboratory\Application\Query\GetOrderSpecimens;
use App\Domains\Laboratory\Contracts\Event\SpecimenCollected;
use App\Domains\Laboratory\Contracts\Event\SpecimenRejected;
use App\Domains\Laboratory\Domain\Exception\IllegalLabSpecimenTransition;
use App\Domains\Laboratory\Domain\Exception\LabOrderNotReadyForSpecimen;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchLabSpec(string $tenantId, ?string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('CollectSpecimen creates a COLLECTED specimen, snapshots the order patient/facility, allocates an accession, advances the order to SPECIMEN_PENDING, emits a PHI-free event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['orderId' => $orderId, 'patientId' => $patientId] = seedAcceptedLabOrder($t, $f, (string) $user->id);

    $specimenId = (string) Str::uuid7();
    dispatchLabSpec($t, $f, new CollectSpecimen(
        specimenId: $specimenId,
        actorId: (string) $user->id,
        orderId: $orderId,
        specimenType: 'whole_blood',
        containerType: 'edta_tube',
        collectionLocation: 'ward 3',
    ));

    $specimen = TenantContextScope::run($t, $f, fn () => LabSpecimen::query()->find($specimenId));
    expect($specimen)->not->toBeNull()
        ->and($specimen->status)->toBe(LabSpecimen::STATUS_COLLECTED)
        ->and((string) $specimen->patient_id)->toBe($patientId)
        ->and((string) $specimen->facility_id)->toBe($f)
        ->and($specimen->specimen_type)->toBe('WHOLE_BLOOD')
        ->and($specimen->container_type)->toBe('EDTA_TUBE')
        ->and($specimen->accession_number)->toStartWith('LABSP-')
        ->and((int) $specimen->version)->toBe(0);

    $order = TenantContextScope::run($t, $f, fn () => LabOrder::query()->find($orderId));
    expect($order->status)->toBe(LabOrder::STATUS_SPECIMEN_PENDING);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', SpecimenCollected::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->payload)->toContain($specimen->accession_number);
});

test('a specimen runs COLLECTED -> RECEIVED -> ACCEPTED, and ACCEPTED advances the order to PROCESSING', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['orderId' => $orderId] = seedAcceptedLabOrder($t, $f, (string) $user->id);

    $specimenId = (string) Str::uuid7();
    dispatchLabSpec($t, $f, new CollectSpecimen(specimenId: $specimenId, actorId: (string) $user->id, orderId: $orderId, specimenType: 'SERUM'));
    dispatchLabSpec($t, $f, new ReceiveSpecimen(specimenId: $specimenId, actorId: (string) $user->id));
    dispatchLabSpec($t, $f, new AcceptSpecimen(specimenId: $specimenId, actorId: (string) $user->id));

    $specimen = TenantContextScope::run($t, $f, fn () => LabSpecimen::query()->find($specimenId));
    expect($specimen->status)->toBe(LabSpecimen::STATUS_ACCEPTED)
        ->and($specimen->received_by_user_id)->toBe((string) $user->id)
        ->and($specimen->received_at)->not->toBeNull();

    $order = TenantContextScope::run($t, $f, fn () => LabOrder::query()->find($orderId));
    expect($order->status)->toBe(LabOrder::STATUS_PROCESSING);
});

test('RejectSpecimen stores the coded reason + free-text detail row-only, and detail is never in the event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['orderId' => $orderId] = seedAcceptedLabOrder($t, $f, (string) $user->id);

    $specimenId = (string) Str::uuid7();
    dispatchLabSpec($t, $f, new CollectSpecimen(specimenId: $specimenId, actorId: (string) $user->id, orderId: $orderId, specimenType: 'WHOLE_BLOOD'));
    dispatchLabSpec($t, $f, new ReceiveSpecimen(specimenId: $specimenId, actorId: (string) $user->id));
    dispatchLabSpec($t, $f, new RejectSpecimen(
        specimenId: $specimenId, actorId: (string) $user->id,
        reasonCode: 'clotted_specimen', detail: 'Visible clot in the EDTA tube on the patient John Doe.',
    ));

    $specimen = TenantContextScope::run($t, $f, fn () => LabSpecimen::query()->find($specimenId));
    expect($specimen->status)->toBe(LabSpecimen::STATUS_REJECTED)
        ->and($specimen->rejection_reason_code)->toBe('CLOTTED_SPECIMEN')
        ->and($specimen->rejection_detail)->toContain('Visible clot');

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', SpecimenRejected::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->toContain('CLOTTED_SPECIMEN')
        ->and($outbox->payload)->not->toContain('John Doe');

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('target_id', $specimenId)->where('action', 'laboratory.specimen_rejected')->first());
    expect($audit->reason)->toContain('Visible clot');
});

test('RequestRecollection works only on a REJECTED specimen, and a recollected specimen links to the original', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['orderId' => $orderId] = seedAcceptedLabOrder($t, $f, (string) $user->id);

    $rejectedId = (string) Str::uuid7();
    dispatchLabSpec($t, $f, new CollectSpecimen(specimenId: $rejectedId, actorId: (string) $user->id, orderId: $orderId, specimenType: 'WHOLE_BLOOD'));

    expect(fn () => dispatchLabSpec($t, $f, new RequestRecollection(specimenId: $rejectedId, actorId: (string) $user->id)))
        ->toThrow(IllegalLabSpecimenTransition::class);

    dispatchLabSpec($t, $f, new RejectSpecimen(specimenId: $rejectedId, actorId: (string) $user->id, reasonCode: 'INSUFFICIENT_QUANTITY'));
    dispatchLabSpec($t, $f, new RequestRecollection(specimenId: $rejectedId, actorId: (string) $user->id));

    $rejected = TenantContextScope::run($t, $f, fn () => LabSpecimen::query()->find($rejectedId));
    expect($rejected->recollection_requested_at)->not->toBeNull();

    $newId = (string) Str::uuid7();
    dispatchLabSpec($t, $f, new CollectSpecimen(
        specimenId: $newId, actorId: (string) $user->id, orderId: $orderId, specimenType: 'WHOLE_BLOOD',
        recollectionOfSpecimenId: $rejectedId,
    ));
    $new = TenantContextScope::run($t, $f, fn () => LabSpecimen::query()->find($newId));
    expect((string) $new->recollection_of_specimen_id)->toBe($rejectedId);
});

test('CollectSpecimen rejects an order that has not been accepted', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $def = seedLabTestDefinition($t);
    ['encounterId' => $enc, 'patientId' => $patientId] = seedActiveLabEncounter($t, $f, (string) $user->id);
    $orderId = (string) Str::uuid7();
    dispatchLabSpec($t, $f, new CreateLabOrder(
        orderId: $orderId, actorId: (string) $user->id, patientId: $patientId, encounterId: $enc,
        testDefinitionIds: [(string) $def->id],
    )); // order is DRAFT

    expect(fn () => dispatchLabSpec($t, $f, new CollectSpecimen(
        specimenId: (string) Str::uuid7(), actorId: (string) $user->id, orderId: $orderId, specimenType: 'WHOLE_BLOOD',
    )))->toThrow(LabOrderNotReadyForSpecimen::class);
});

test('collecting requires laboratory.specimens.collect; receiving requires laboratory.specimens.receive', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture(permissions: [
        'laboratory.access', 'laboratory.orders.create', 'laboratory.orders.accept', 'laboratory.specimens.collect',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['orderId' => $orderId] = seedAcceptedLabOrder($t, $f, (string) $user->id);

    $specimenId = (string) Str::uuid7();
    dispatchLabSpec($t, $f, new CollectSpecimen(specimenId: $specimenId, actorId: (string) $user->id, orderId: $orderId, specimenType: 'WHOLE_BLOOD'));

    expect(fn () => dispatchLabSpec($t, $f, new ReceiveSpecimen(specimenId: $specimenId, actorId: (string) $user->id)))
        ->toThrow(AuthorizationException::class);
});

test('GetLabSpecimen and GetOrderSpecimens return the specimen history including rejected ones', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['orderId' => $orderId] = seedAcceptedLabOrder($t, $f, (string) $user->id);

    $rejectedId = (string) Str::uuid7();
    dispatchLabSpec($t, $f, new CollectSpecimen(specimenId: $rejectedId, actorId: (string) $user->id, orderId: $orderId, specimenType: 'WHOLE_BLOOD'));
    dispatchLabSpec($t, $f, new RejectSpecimen(specimenId: $rejectedId, actorId: (string) $user->id, reasonCode: 'LEAKING_CONTAINER'));

    $goodId = (string) Str::uuid7();
    dispatchLabSpec($t, $f, new CollectSpecimen(specimenId: $goodId, actorId: (string) $user->id, orderId: $orderId, specimenType: 'WHOLE_BLOOD'));

    $one = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetLabSpecimen(specimenId: $goodId)));
    expect($one->specimenId)->toBe($goodId);

    $all = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetOrderSpecimens(orderId: $orderId)));
    expect($all)->toHaveCount(2)
        ->and(collect($all)->pluck('status')->all())->toContain(LabSpecimen::STATUS_REJECTED, LabSpecimen::STATUS_COLLECTED);
});
