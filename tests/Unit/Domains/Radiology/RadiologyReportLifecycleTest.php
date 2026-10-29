<?php

declare(strict_types=1);

use App\Domains\Radiology\Contracts\Event\RadiologyReportCreated;
use App\Domains\Radiology\Domain\Exception\IllegalRadiologyReportTransition;
use App\Domains\Radiology\Domain\Exception\RadiologyReportNotReleasable;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\Service\RadiologyReportLifecycle;
use App\Domains\Radiology\Domain\Service\RadiologyReportReleaseGate;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->lifecycle = new RadiologyReportLifecycle;
    $this->gate = new RadiologyReportReleaseGate;
});

test('the §103 machine: DRAFT -> REVIEWED -> VERIFIED -> RELEASED', function () {
    $this->lifecycle->assertCanMarkReviewed(RadiologyReport::STATUS_DRAFT);
    $this->lifecycle->assertCanVerify(RadiologyReport::STATUS_DRAFT);
    $this->lifecycle->assertCanVerify(RadiologyReport::STATUS_REVIEWED);
    $this->lifecycle->assertCanRelease(RadiologyReport::STATUS_VERIFIED);
    $this->lifecycle->assertCanUpdate(RadiologyReport::STATUS_DRAFT);

    expect(fn () => $this->lifecycle->assertCanVerify(RadiologyReport::STATUS_VERIFIED))
        ->toThrow(IllegalRadiologyReportTransition::class)
        ->and(fn () => $this->lifecycle->assertCanRelease(RadiologyReport::STATUS_DRAFT))
        ->toThrow(IllegalRadiologyReportTransition::class)
        ->and(fn () => $this->lifecycle->assertCanUpdate(RadiologyReport::STATUS_VERIFIED))
        ->toThrow(IllegalRadiologyReportTransition::class);
});

test('amend applies to a finalized report only; cancel never to a RELEASED report', function () {
    foreach ([RadiologyReport::STATUS_VERIFIED, RadiologyReport::STATUS_RELEASED, RadiologyReport::STATUS_AMENDED] as $status) {
        $this->lifecycle->assertCanAmend($status);
    }
    expect(fn () => $this->lifecycle->assertCanAmend(RadiologyReport::STATUS_DRAFT))
        ->toThrow(IllegalRadiologyReportTransition::class);

    foreach ([RadiologyReport::STATUS_DRAFT, RadiologyReport::STATUS_REVIEWED, RadiologyReport::STATUS_VERIFIED] as $status) {
        $this->lifecycle->assertCanCancel($status);
    }
    expect(fn () => $this->lifecycle->assertCanCancel(RadiologyReport::STATUS_RELEASED))
        ->toThrow(IllegalRadiologyReportTransition::class)
        ->and(fn () => $this->lifecycle->assertCanCancel(RadiologyReport::STATUS_AMENDED))
        ->toThrow(IllegalRadiologyReportTransition::class);
});

test('the release gate requires VERIFIED and a clear hold', function () {
    $verified = RadiologyReport::factory()->make(['status' => RadiologyReport::STATUS_VERIFIED, 'release_hold_reason' => null]);
    $this->gate->assertReleasable($verified);

    $held = RadiologyReport::factory()->make(['status' => RadiologyReport::STATUS_VERIFIED, 'release_hold_reason' => 'PEER_REVIEW_PENDING']);
    expect(fn () => $this->gate->assertReleasable($held))->toThrow(RadiologyReportNotReleasable::class);

    $draft = RadiologyReport::factory()->make(['status' => RadiologyReport::STATUS_DRAFT]);
    expect(fn () => $this->gate->assertReleasable($draft))->toThrow(RadiologyReportNotReleasable::class);
});

test('create() records a PHI-free RadiologyReportCreated; report prose is never in the event', function () {
    $report = RadiologyReport::create([
        'id' => (string) Str::uuid7(), 'tenantId' => (string) Str::uuid7(), 'facilityId' => (string) Str::uuid7(),
        'studyId' => (string) Str::uuid7(), 'orderId' => (string) Str::uuid7(), 'orderItemId' => (string) Str::uuid7(),
        'patientId' => (string) Str::uuid7(), 'encounterId' => (string) Str::uuid7(),
        'accessionNumber' => 'RADAC-00000009', 'modalityCode' => 'CT', 'serviceCode' => 'CT-HEAD',
        'clinicalIndicationText' => null, 'techniqueText' => null,
        'findingsText' => 'Acute subdural haematoma with midline shift.', 'impressionText' => 'Surgical emergency.',
        'recommendationsText' => null,
        'authoredByUserId' => (string) Str::uuid7(),
    ]);

    expect($report->status)->toBe(RadiologyReport::STATUS_DRAFT);
    $events = $report->releaseEvents();
    expect($events)->toHaveCount(1)->and($events[0])->toBeInstanceOf(RadiologyReportCreated::class)
        ->and(json_encode($events[0]))->not->toContain('haematoma')
        ->and($events[0]->accessionNumber)->toBe('RADAC-00000009');
});
