<?php

declare(strict_types=1);

use App\Domains\Radiology\Application\Command\CreateRadiologyReport;
use App\Domains\Radiology\Application\Command\MarkReportReviewed;
use App\Domains\Radiology\Application\Command\ReleaseRadiologyReport;
use App\Domains\Radiology\Application\Command\UpdateRadiologyReport;
use App\Domains\Radiology\Application\Command\VerifyRadiologyReport;
use App\Domains\Radiology\Application\Query\GetPendingReports;
use App\Domains\Radiology\Application\Query\GetRadiologyReport;
use App\Domains\Radiology\Application\Query\GetStudyReport;
use App\Domains\Radiology\Contracts\Event\RadiologyReportCreated;
use App\Domains\Radiology\Contracts\Event\RadiologyReportReleased;
use App\Domains\Radiology\Contracts\Event\RadiologyReportVerified;
use App\Domains\Radiology\Domain\Exception\IllegalRadiologyReportTransition;
use App\Domains\Radiology\Domain\Exception\RadiologyReportAlreadyExists;
use App\Domains\Radiology\Domain\Exception\RadiologyReportIncomplete;
use App\Domains\Radiology\Domain\Exception\StudyNotReportable;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchReport(string $t, ?string $f, object $command): mixed
{
    return TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch($command));
}

test('CreateRadiologyReport snapshots the study, starts DRAFT, emits a PHI-free event, audits, and rolls the order to REPORTED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['studyId' => $studyId, 'orderId' => $orderId, 'patientId' => $patientId] = seedCompletedImagingStudy($t, $f, $a);

    $reportId = (string) Str::uuid7();
    dispatchReport($t, $f, new CreateRadiologyReport(
        reportId: $reportId, studyId: $studyId, actorId: $a,
        findingsText: 'Right lower-lobe opacity on the patient Jane Roe.',
        impressionText: 'Pneumonia.',
    ));

    $report = TenantContextScope::run($t, $f, fn () => RadiologyReport::query()->find($reportId));
    expect($report->status)->toBe(RadiologyReport::STATUS_DRAFT)
        ->and((string) $report->study_id)->toBe($studyId)
        ->and((string) $report->patient_id)->toBe($patientId)
        ->and($report->accession_number)->toStartWith('RADAC-')
        ->and((int) $report->version)->toBe(0);

    $order = TenantContextScope::run($t, $f, fn () => ImagingOrder::query()->find($orderId));
    expect($order->status)->toBe(ImagingOrder::STATUS_REPORTED);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', RadiologyReportCreated::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->payload)->not->toContain('Jane Roe');

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('target_id', $reportId)->where('action', 'radiology.report_created')->exists()))->toBeTrue();
});

test('a report runs DRAFT -> REVIEWED -> VERIFIED -> RELEASED and ratchets the order the same way', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId, 'orderId' => $orderId] = seedDraftRadiologyReport($t, $f, $a);

    dispatchReport($t, $f, new MarkReportReviewed(reportId: $reportId, actorId: $a));
    dispatchReport($t, $f, new VerifyRadiologyReport(reportId: $reportId, actorId: $a));

    $afterVerify = TenantContextScope::run($t, $f, fn () => [RadiologyReport::query()->find($reportId), ImagingOrder::query()->find($orderId)]);
    expect($afterVerify[0]->status)->toBe(RadiologyReport::STATUS_VERIFIED)
        ->and($afterVerify[0]->verified_by_user_id)->toBe($a)
        ->and($afterVerify[1]->status)->toBe(ImagingOrder::STATUS_VERIFIED);

    dispatchReport($t, $f, new ReleaseRadiologyReport(reportId: $reportId, actorId: $a));

    $afterRelease = TenantContextScope::run($t, $f, fn () => [RadiologyReport::query()->find($reportId), ImagingOrder::query()->find($orderId)]);
    expect($afterRelease[0]->status)->toBe(RadiologyReport::STATUS_RELEASED)
        ->and($afterRelease[1]->status)->toBe(ImagingOrder::STATUS_RELEASED);

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', RadiologyReportVerified::class)->count()))->toBe(1)
        ->and(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', RadiologyReportReleased::class)->count()))->toBe(1);
});

test('an empty report cannot be verified; a second report for the same study is rejected; a non-completed study is not reportable', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['studyId' => $studyId] = seedCompletedImagingStudy($t, $f, $a);

    $reportId = (string) Str::uuid7();
    dispatchReport($t, $f, new CreateRadiologyReport(reportId: $reportId, studyId: $studyId, actorId: $a)); // no findings/impression
    expect(fn () => dispatchReport($t, $f, new VerifyRadiologyReport(reportId: $reportId, actorId: $a)))
        ->toThrow(RadiologyReportIncomplete::class);

    expect(fn () => dispatchReport($t, $f, new CreateRadiologyReport(reportId: (string) Str::uuid7(), studyId: $studyId, actorId: $a)))
        ->toThrow(RadiologyReportAlreadyExists::class);

    ['studyId' => $scheduledStudy] = seedScheduledImagingStudy($t, $f, $a);
    expect(fn () => dispatchReport($t, $f, new CreateRadiologyReport(reportId: (string) Str::uuid7(), studyId: $scheduledStudy, actorId: $a)))
        ->toThrow(StudyNotReportable::class);
});

test('UpdateRadiologyReport edits DRAFT sections; a verified report is no longer editable', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId] = seedDraftRadiologyReport($t, $f, $a);

    dispatchReport($t, $f, new UpdateRadiologyReport(reportId: $reportId, actorId: $a, sections: [
        'techniqueText' => 'Non-contrast CT head.',
        'recommendationsText' => 'Neurosurgical review.',
    ]));

    $report = TenantContextScope::run($t, $f, fn () => RadiologyReport::query()->find($reportId));
    expect($report->technique_text)->toBe('Non-contrast CT head.')
        ->and($report->recommendations_text)->toBe('Neurosurgical review.');

    dispatchReport($t, $f, new VerifyRadiologyReport(reportId: $reportId, actorId: $a));
    expect(fn () => dispatchReport($t, $f, new UpdateRadiologyReport(reportId: $reportId, actorId: $a, sections: ['findingsText' => 'x'])))
        ->toThrow(IllegalRadiologyReportTransition::class);
});

test('create requires radiology.reports.create; verify requires radiology.reports.verify; release requires radiology.reports.release', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture(permissions: [
        'radiology.access', 'radiology.orders.create', 'radiology.orders.accept', 'radiology.studies.perform',
        'radiology.reports.create',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId] = seedDraftRadiologyReport($t, $f, $a);

    expect(fn () => dispatchReport($t, $f, new VerifyRadiologyReport(reportId: $reportId, actorId: $a)))
        ->toThrow(AuthorizationException::class);
});

test('GetRadiologyReport, GetStudyReport and GetPendingReports return the report', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId, 'studyId' => $studyId] = seedDraftRadiologyReport($t, $f, $a);

    $one = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetRadiologyReport(reportId: $reportId)));
    expect($one->reportId)->toBe($reportId)->and($one->findingsText)->toBe('Right lower-lobe opacity.');

    $byStudy = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetStudyReport(studyId: $studyId)));
    expect($byStudy->reportId)->toBe($reportId);

    $pending = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetPendingReports(facilityId: $f)));
    expect($pending)->toHaveCount(1)->and($pending[0]->reportId)->toBe($reportId);
});
