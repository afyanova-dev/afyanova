<?php

declare(strict_types=1);

use App\Domains\Radiology\Application\Command\AbortImagingStudy;
use App\Domains\Radiology\Application\Command\CancelImagingStudy;
use App\Domains\Radiology\Application\Command\CompleteImagingStudy;
use App\Domains\Radiology\Application\Command\MarkStudyReady;
use App\Domains\Radiology\Application\Command\RecordImagingNoShow;
use App\Domains\Radiology\Application\Command\ScheduleImagingStudy;
use App\Domains\Radiology\Application\Command\StartImagingStudy;
use App\Domains\Radiology\Application\Query\GetImagingStudy;
use App\Domains\Radiology\Application\Query\GetOrderStudies;
use App\Domains\Radiology\Application\Query\GetRadiologyWorklist;
use App\Domains\Radiology\Contracts\Event\ImagingStudyCompleted;
use App\Domains\Radiology\Contracts\Event\ImagingStudyScheduled;
use App\Domains\Radiology\Domain\Exception\IllegalImagingStudyTransition;
use App\Domains\Radiology\Domain\Exception\ImagingStudyAlreadyActive;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Model\ImagingOrderItem;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchStudy(string $t, ?string $f, object $command): mixed
{
    return TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch($command));
}

test('ScheduleImagingStudy creates a SCHEDULED study, snapshots the order + item, allocates a RADAC accession, emits a PHI-free event, audits, and rolls the order to SCHEDULED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['orderId' => $orderId, 'patientId' => $patientId, 'encounterId' => $encounterId, 'modalityCode' => $modalityCode, 'serviceCode' => $serviceCode] =
        seedAcceptedImagingOrder($t, $f, $a);
    $orderItemId = (string) TenantContextScope::run($t, $f, fn () => ImagingOrderItem::query()->where('order_id', $orderId)->value('id'));

    $studyId = (string) Str::uuid7();
    dispatchStudy($t, $f, new ScheduleImagingStudy(
        studyId: $studyId, orderId: $orderId, orderItemId: $orderItemId, actorId: $a,
        appointmentRef: 'SCH-APPT-991', equipmentRef: 'XR-ROOM-2',
    ));

    $study = TenantContextScope::run($t, $f, fn () => ImagingStudy::query()->find($studyId));
    expect($study->status)->toBe(ImagingStudy::STATUS_SCHEDULED)
        ->and((string) $study->order_id)->toBe($orderId)
        ->and((string) $study->order_item_id)->toBe($orderItemId)
        ->and((string) $study->patient_id)->toBe($patientId)
        ->and((string) $study->encounter_id)->toBe($encounterId)
        ->and($study->accession_number)->toStartWith('RADAC-')
        ->and($study->modality_code)->toBe($modalityCode)
        ->and($study->service_code)->toBe($serviceCode)
        ->and($study->appointment_ref)->toBe('SCH-APPT-991')
        ->and((int) $study->version)->toBe(0);

    $order = TenantContextScope::run($t, $f, fn () => ImagingOrder::query()->find($orderId));
    expect($order->status)->toBe(ImagingOrder::STATUS_SCHEDULED);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', ImagingStudyScheduled::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->payload)->toContain($study->accession_number);

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('target_id', $studyId)->where('action', 'radiology.study_scheduled')->exists()))->toBeTrue();
});

test('a study runs SCHEDULED -> READY -> IN_PROGRESS -> COMPLETED and rolls the order forward each step; ImagingStudyCompleted is emitted once', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['studyId' => $studyId, 'orderId' => $orderId] = seedScheduledImagingStudy($t, $f, $a);

    dispatchStudy($t, $f, new MarkStudyReady(studyId: $studyId, actorId: $a));
    dispatchStudy($t, $f, new StartImagingStudy(studyId: $studyId, actorId: $a, patientVerified: true));

    $mid = TenantContextScope::run($t, $f, fn () => [
        ImagingStudy::query()->find($studyId), ImagingOrder::query()->find($orderId),
    ]);
    expect($mid[0]->status)->toBe(ImagingStudy::STATUS_IN_PROGRESS)
        ->and($mid[0]->verified_by_user_id)->toBe($a)
        ->and($mid[0]->verified_at)->not->toBeNull()
        ->and($mid[1]->status)->toBe(ImagingOrder::STATUS_IN_PROGRESS);

    dispatchStudy($t, $f, new CompleteImagingStudy(studyId: $studyId, actorId: $a));

    $done = TenantContextScope::run($t, $f, fn () => [
        ImagingStudy::query()->find($studyId), ImagingOrder::query()->find($orderId),
    ]);
    expect($done[0]->status)->toBe(ImagingStudy::STATUS_COMPLETED)
        ->and($done[0]->completed_at)->not->toBeNull()
        ->and($done[1]->status)->toBe(ImagingOrder::STATUS_COMPLETED);

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', ImagingStudyCompleted::class)->count()))->toBe(1);
});

test('StartImagingStudy rejects patientVerified=false (the §54 wrong-patient gate)', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;
    ['studyId' => $studyId] = seedScheduledImagingStudy($t, $f, $a);

    expect(fn () => new StartImagingStudy(studyId: $studyId, actorId: $a, patientVerified: false))
        ->toThrow(InvalidArgumentException::class);
});

test('cancel stores the reason code + row-only detail and keeps detail out of the event; a completed study cannot be cancelled', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;
    ['studyId' => $studyId] = seedScheduledImagingStudy($t, $f, $a);

    dispatchStudy($t, $f, new CancelImagingStudy(studyId: $studyId, actorId: $a, reasonCode: 'equipment_unavailable', detail: 'CT down for the day per biomed ticket 4471.'));

    $study = TenantContextScope::run($t, $f, fn () => ImagingStudy::query()->find($studyId));
    expect($study->status)->toBe(ImagingStudy::STATUS_CANCELLED)
        ->and($study->outcome_status_code)->toBe('EQUIPMENT_UNAVAILABLE')
        ->and($study->outcome_detail)->toBe('CT down for the day per biomed ticket 4471.');

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', 'App\\Domains\\Radiology\\Contracts\\Event\\ImagingStudyCancelled')->first());
    expect($outbox->payload)->not->toContain('biomed ticket')->and($outbox->payload)->toContain('EQUIPMENT_UNAVAILABLE');

    expect(fn () => dispatchStudy($t, $f, new CancelImagingStudy(studyId: $studyId, actorId: $a, reasonCode: 'OTHER')))
        ->toThrow(IllegalImagingStudyTransition::class);
});

test('no-show and abort record distinct terminal states; abort requires a non-blank detail', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['studyId' => $noShowStudy] = seedScheduledImagingStudy($t, $f, $a);
    dispatchStudy($t, $f, new RecordImagingNoShow(studyId: $noShowStudy, actorId: $a));
    expect(TenantContextScope::run($t, $f, fn () => ImagingStudy::query()->find($noShowStudy)->status))->toBe(ImagingStudy::STATUS_NO_SHOW);

    ['studyId' => $abortStudy] = seedScheduledImagingStudy($t, $f, $a);
    dispatchStudy($t, $f, new StartImagingStudy(studyId: $abortStudy, actorId: $a, patientVerified: true));
    expect(fn () => new AbortImagingStudy(studyId: $abortStudy, actorId: $a, reasonCode: 'EQUIPMENT_ERROR', detail: '  '))
        ->toThrow(InvalidArgumentException::class);
    dispatchStudy($t, $f, new AbortImagingStudy(studyId: $abortStudy, actorId: $a, reasonCode: 'equipment_error', detail: 'Scanner faulted mid-acquisition.'));
    expect(TenantContextScope::run($t, $f, fn () => ImagingStudy::query()->find($abortStudy)->status))->toBe(ImagingStudy::STATUS_ABORTED);
});

test('an order item cannot have two active studies; a re-schedule after a no-show is allowed', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['studyId' => $first, 'orderId' => $orderId, 'orderItemId' => $orderItemId] = seedScheduledImagingStudy($t, $f, $a);

    expect(fn () => dispatchStudy($t, $f, new ScheduleImagingStudy(
        studyId: (string) Str::uuid7(), orderId: $orderId, orderItemId: $orderItemId, actorId: $a,
    )))->toThrow(ImagingStudyAlreadyActive::class);

    dispatchStudy($t, $f, new RecordImagingNoShow(studyId: $first, actorId: $a));

    $second = (string) Str::uuid7();
    dispatchStudy($t, $f, new ScheduleImagingStudy(
        studyId: $second, orderId: $orderId, orderItemId: $orderItemId, actorId: $a, repeatOfStudyId: $first,
    ));
    expect(TenantContextScope::run($t, $f, fn () => ImagingStudy::query()->find($second)->status))->toBe(ImagingStudy::STATUS_SCHEDULED);
});

test('scheduling / performing require radiology.studies.perform', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture(permissions: [
        'radiology.access', 'radiology.orders.create', 'radiology.orders.accept',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['orderId' => $orderId] = seedAcceptedImagingOrder($t, $f, $a);
    $orderItemId = (string) TenantContextScope::run($t, $f, fn () => ImagingOrderItem::query()->where('order_id', $orderId)->value('id'));

    expect(fn () => dispatchStudy($t, $f, new ScheduleImagingStudy(
        studyId: (string) Str::uuid7(), orderId: $orderId, orderItemId: $orderItemId, actorId: $a,
    )))->toThrow(AuthorizationException::class);
});

test('GetImagingStudy, GetOrderStudies and GetRadiologyWorklist return the study', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['studyId' => $studyId, 'orderId' => $orderId] = seedScheduledImagingStudy($t, $f, $a);

    $one = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetImagingStudy(imagingStudyId: $studyId)));
    expect($one->imagingStudyId)->toBe($studyId)->and($one->status)->toBe(ImagingStudy::STATUS_SCHEDULED);

    $forOrder = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetOrderStudies(orderId: $orderId)));
    expect($forOrder)->toHaveCount(1)->and($forOrder[0]->imagingStudyId)->toBe($studyId);

    $worklist = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetRadiologyWorklist(facilityId: $f)));
    expect($worklist)->toHaveCount(1)->and($worklist[0]->imagingStudyId)->toBe($studyId);
});
