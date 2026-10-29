<?php

declare(strict_types=1);

use App\Domains\Radiology\Application\Command\AmendRadiologyReport;
use App\Domains\Radiology\Application\Command\CancelRadiologyReport;
use App\Domains\Radiology\Application\Query\GetReportAmendments;
use App\Domains\Radiology\Contracts\Event\RadiologyReportAmended;
use App\Domains\Radiology\Domain\Exception\IllegalRadiologyReportTransition;
use App\Domains\Radiology\Domain\Exception\ReportAmendmentIncomplete;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\Model\RadiologyReportAmendment;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchReportAmend(string $t, ?string $f, object $command): mixed
{
    return TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch($command));
}

test('a CORRECTION replaces the section text, appends a row, flips the report to AMENDED, emits a PHI-free event; the reason is row-only', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId] = seedReleasedRadiologyReport($t, $f, $a);

    dispatchReportAmend($t, $f, new AmendRadiologyReport(
        amendmentId: (string) Str::uuid7(), reportId: $reportId, actorId: $a,
        amendmentType: 'correction',
        reason: 'Corrected laterality after PACS review — case ref 7781.',
        findingsText: 'Left lower-lobe consolidation (previously reported right).',
        impressionText: 'Left lower-lobe pneumonia.',
    ));

    $report = TenantContextScope::run($t, $f, fn () => RadiologyReport::query()->find($reportId));
    expect($report->status)->toBe(RadiologyReport::STATUS_AMENDED)
        ->and($report->findings_text)->toBe('Left lower-lobe consolidation (previously reported right).')
        ->and((int) $report->amendment_count)->toBe(1);

    $row = TenantContextScope::run($t, $f, fn () => RadiologyReportAmendment::query()->where('report_id', $reportId)->first());
    expect($row)->not->toBeNull()
        ->and($row->amendment_type)->toBe(RadiologyReportAmendment::TYPE_CORRECTION)
        ->and($row->previous_status)->toBe(RadiologyReport::STATUS_RELEASED)
        ->and($row->reason)->toBe('Corrected laterality after PACS review — case ref 7781.')
        ->and((int) $row->sequence)->toBe(1);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', RadiologyReportAmended::class)->first());
    expect($outbox->payload)->not->toContain('case ref 7781');

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('target_id', $reportId)->where('action', 'radiology.report_amended')->value('reason')))->toBe('Corrected laterality after PACS review — case ref 7781.');
});

test('an ADDENDUM appends labelled supplementary text and keeps the original', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId] = seedReleasedRadiologyReport($t, $f, $a);

    dispatchReportAmend($t, $f, new AmendRadiologyReport(
        amendmentId: (string) Str::uuid7(), reportId: $reportId, actorId: $a,
        amendmentType: 'ADDENDUM', reason: 'Comparison with the prior study.',
        impressionText: 'Stable compared with the study from 3 months ago.',
    ));

    $report = TenantContextScope::run($t, $f, fn () => RadiologyReport::query()->find($reportId));
    expect($report->impression_text)->toContain('Consistent with pneumonia.')
        ->and($report->impression_text)->toContain('[ADDENDUM — IMPRESSION] Stable compared with the study from 3 months ago.');
});

test('the amendments table is append-only at the database — UPDATE / DELETE affect 0 rows', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId] = seedReleasedRadiologyReport($t, $f, $a);
    dispatchReportAmend($t, $f, new AmendRadiologyReport(
        amendmentId: (string) Str::uuid7(), reportId: $reportId, actorId: $a,
        amendmentType: 'CORRECTION', reason: 'x', findingsText: 'y', impressionText: 'z',
    ));

    $policies = DB::table('pg_policies')->where('tablename', 'rad_report_amendments')->pluck('policyname')->all();
    expect($policies)->toContain('rad_report_amendments_no_update')
        ->and($policies)->toContain('rad_report_amendments_no_delete');

    $raw = DB::connection('pgsql_raw');
    $rowId = Tenancy::run($t, fn () => DB::table('rad_report_amendments')->where('report_id', $reportId)->value('id'));

    $raw->transaction(function () use ($raw, $t, $rowId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$t]);
        expect($raw->table('rad_report_amendments')->where('id', $rowId)->update(['reason' => 'tampered']))->toBe(0);
        expect($raw->table('rad_report_amendments')->where('id', $rowId)->delete())->toBe(0);
    });

    expect(Tenancy::run($t, fn () => DB::table('rad_report_amendments')->where('id', $rowId)->value('reason')))->not->toBe('tampered');
});

test('amend / cancel require a non-blank reason; a released report cannot be cancelled; GetReportAmendments lists the history', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $releasedReport] = seedReleasedRadiologyReport($t, $f, $a);

    expect(fn () => new AmendRadiologyReport(amendmentId: (string) Str::uuid7(), reportId: $releasedReport, actorId: $a, amendmentType: 'CORRECTION', reason: '  '))
        ->toThrow(ReportAmendmentIncomplete::class)
        ->and(fn () => dispatchReportAmend($t, $f, new CancelRadiologyReport(reportId: $releasedReport, actorId: $a, reason: 'too late')))
        ->toThrow(IllegalRadiologyReportTransition::class);

    dispatchReportAmend($t, $f, new AmendRadiologyReport(amendmentId: (string) Str::uuid7(), reportId: $releasedReport, actorId: $a, amendmentType: 'ADDENDUM', reason: 'a', recommendationsText: 'Follow-up CT in 6 weeks.'));
    dispatchReportAmend($t, $f, new AmendRadiologyReport(amendmentId: (string) Str::uuid7(), reportId: $releasedReport, actorId: $a, amendmentType: 'CORRECTION', reason: 'b', findingsText: 'refined', impressionText: 'refined'));

    $history = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetReportAmendments(reportId: $releasedReport)));
    expect($history)->toHaveCount(2)->and($history[0]->sequence)->toBe(1)->and($history[1]->sequence)->toBe(2);

    ['reportId' => $verifiedReport] = seedVerifiedRadiologyReport($t, $f, $a);
    dispatchReportAmend($t, $f, new CancelRadiologyReport(reportId: $verifiedReport, actorId: $a, reason: 'Wrong study.'));
    expect(TenantContextScope::run($t, $f, fn () => RadiologyReport::query()->find($verifiedReport)->status))->toBe(RadiologyReport::STATUS_CANCELLED);
});
