<?php

declare(strict_types=1);

use App\Domains\Radiology\Contracts\Event\ImagingStudyCompleted;
use App\Domains\Radiology\Contracts\Event\ImagingStudyScheduled;
use App\Domains\Radiology\Domain\Exception\IllegalImagingStudyTransition;
use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Domains\Radiology\Domain\Service\ImagingStudyLifecycle;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->lifecycle = new ImagingStudyLifecycle;
});

test('the §104 machine: SCHEDULED -> READY -> IN_PROGRESS -> COMPLETED', function () {
    $this->lifecycle->assertCanMarkReady(ImagingStudy::STATUS_SCHEDULED);
    $this->lifecycle->assertCanStart(ImagingStudy::STATUS_SCHEDULED);
    $this->lifecycle->assertCanStart(ImagingStudy::STATUS_READY);
    $this->lifecycle->assertCanComplete(ImagingStudy::STATUS_IN_PROGRESS);

    expect(fn () => $this->lifecycle->assertCanMarkReady(ImagingStudy::STATUS_IN_PROGRESS))
        ->toThrow(IllegalImagingStudyTransition::class)
        ->and(fn () => $this->lifecycle->assertCanStart(ImagingStudy::STATUS_IN_PROGRESS))
        ->toThrow(IllegalImagingStudyTransition::class)
        ->and(fn () => $this->lifecycle->assertCanComplete(ImagingStudy::STATUS_SCHEDULED))
        ->toThrow(IllegalImagingStudyTransition::class);
});

test('cancel / no-show are pre-acquisition only; abort is in-progress only', function () {
    foreach ([ImagingStudy::STATUS_SCHEDULED, ImagingStudy::STATUS_READY] as $status) {
        $this->lifecycle->assertCanCancel($status);
        $this->lifecycle->assertCanRecordNoShow($status);
    }

    expect(fn () => $this->lifecycle->assertCanCancel(ImagingStudy::STATUS_IN_PROGRESS))
        ->toThrow(IllegalImagingStudyTransition::class)
        ->and(fn () => $this->lifecycle->assertCanRecordNoShow(ImagingStudy::STATUS_IN_PROGRESS))
        ->toThrow(IllegalImagingStudyTransition::class)
        ->and(fn () => $this->lifecycle->assertCanAbort(ImagingStudy::STATUS_SCHEDULED))
        ->toThrow(IllegalImagingStudyTransition::class);

    $this->lifecycle->assertCanAbort(ImagingStudy::STATUS_IN_PROGRESS);
});

test('schedule() and complete() record PHI-free events; outcome_detail is never carried', function () {
    $study = ImagingStudy::schedule([
        'id' => (string) Str::uuid7(), 'tenantId' => (string) Str::uuid7(), 'facilityId' => (string) Str::uuid7(),
        'orderId' => (string) Str::uuid7(), 'orderItemId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(), 'encounterId' => (string) Str::uuid7(),
        'accessionNumber' => 'RADAC-00000001', 'modalityCode' => 'CT', 'serviceCode' => 'CT-HEAD',
        'bodySite' => 'HEAD', 'laterality' => null, 'scheduledFor' => null,
        'appointmentRef' => null, 'equipmentRef' => null, 'repeatOfStudyId' => null,
        'createdByUserId' => (string) Str::uuid7(),
    ]);

    expect($study->status)->toBe(ImagingStudy::STATUS_SCHEDULED);
    $scheduled = $study->releaseEvents();
    expect($scheduled)->toHaveCount(1)->and($scheduled[0])->toBeInstanceOf(ImagingStudyScheduled::class)
        ->and($scheduled[0]->accessionNumber)->toBe('RADAC-00000001');

    $study->start((string) Str::uuid7(), (string) Str::uuid7());
    $study->releaseEvents();
    $study->complete();

    $completed = $study->releaseEvents();
    expect($completed)->toHaveCount(1)->and($completed[0])->toBeInstanceOf(ImagingStudyCompleted::class);
});
