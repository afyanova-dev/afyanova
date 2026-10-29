<?php

declare(strict_types=1);

use App\Domains\Radiology\Application\Command\AcknowledgeCriticalFinding;
use App\Domains\Radiology\Application\Command\RecordCriticalFinding;
use App\Domains\Radiology\Application\Query\GetCriticalFinding;
use App\Domains\Radiology\Application\Query\GetPendingCriticalFindings;
use App\Domains\Radiology\Contracts\Event\CriticalImagingFindingAcknowledged;
use App\Domains\Radiology\Contracts\Event\CriticalImagingFindingIdentified;
use App\Domains\Radiology\Domain\Exception\IllegalRadiologyCriticalFindingTransition;
use App\Domains\Radiology\Domain\Exception\IllegalRadiologyReportTransition;
use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
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

function dispatchCf(string $t, ?string $f, object $command): mixed
{
    return TenantContextScope::run($t, $f, fn () => app(CommandBus::class)->dispatch($command));
}

test('RecordCriticalFinding creates an INITIATED finding, flags the report, emits a PHI-free event, audits', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId] = seedVerifiedRadiologyReport($t, $f, $a);

    $cfId = (string) Str::uuid7();
    dispatchCf($t, $f, new RecordCriticalFinding(
        criticalFindingId: $cfId, reportId: $reportId, actorId: $a,
        findingSummary: 'Tension pneumothorax on the right — patient Mark Poe.',
        severity: 'critical',
    ));

    $finding = TenantContextScope::run($t, $f, fn () => RadiologyCriticalFinding::query()->find($cfId));
    expect($finding->status)->toBe(RadiologyCriticalFinding::STATUS_INITIATED)
        ->and($finding->severity)->toBe('CRITICAL')
        ->and((string) $finding->report_id)->toBe($reportId);

    $report = TenantContextScope::run($t, $f, fn () => RadiologyReport::query()->find($reportId));
    expect((bool) $report->critical_finding_flagged)->toBeTrue();

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', CriticalImagingFindingIdentified::class)->first());
    expect($outbox)->not->toBeNull()->and($outbox->payload)->not->toContain('Mark Poe')->and($outbox->payload)->toContain('CRITICAL');

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('target_id', $cfId)->where('action', 'radiology.critical_finding_identified')->exists()))->toBeTrue();
});

test('a report can carry several critical findings, each acknowledged independently', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId] = seedReleasedRadiologyReport($t, $f, $a);

    $one = (string) Str::uuid7();
    $two = (string) Str::uuid7();
    dispatchCf($t, $f, new RecordCriticalFinding(criticalFindingId: $one, reportId: $reportId, actorId: $a, findingSummary: 'Pneumothorax.', severity: 'CRITICAL'));
    dispatchCf($t, $f, new RecordCriticalFinding(criticalFindingId: $two, reportId: $reportId, actorId: $a, findingSummary: 'Free intraperitoneal air.', severity: 'CRITICAL'));

    dispatchCf($t, $f, new AcknowledgeCriticalFinding(criticalFindingId: $one, actorId: $a, note: 'Chest team informed 14:05.'));

    $states = TenantContextScope::run($t, $f, fn () => [
        RadiologyCriticalFinding::query()->find($one)->status,
        RadiologyCriticalFinding::query()->find($two)->status,
    ]);
    expect($states[0])->toBe(RadiologyCriticalFinding::STATUS_ACKNOWLEDGED)
        ->and($states[1])->toBe(RadiologyCriticalFinding::STATUS_INITIATED);

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', CriticalImagingFindingAcknowledged::class)->count()))->toBe(1);

    $pending = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetPendingCriticalFindings(facilityId: $f)));
    expect($pending)->toHaveCount(1)->and($pending[0]->criticalFindingId)->toBe($two);
});

test('a critical finding cannot be recorded against a DRAFT report; a second acknowledgement is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $draftReport] = seedDraftRadiologyReport($t, $f, $a);
    expect(fn () => dispatchCf($t, $f, new RecordCriticalFinding(criticalFindingId: (string) Str::uuid7(), reportId: $draftReport, actorId: $a, findingSummary: 'x', severity: 'CRITICAL')))
        ->toThrow(IllegalRadiologyReportTransition::class);

    ['reportId' => $verifiedReport] = seedVerifiedRadiologyReport($t, $f, $a);
    $cfId = (string) Str::uuid7();
    dispatchCf($t, $f, new RecordCriticalFinding(criticalFindingId: $cfId, reportId: $verifiedReport, actorId: $a, findingSummary: 'x', severity: 'HIGH'));
    dispatchCf($t, $f, new AcknowledgeCriticalFinding(criticalFindingId: $cfId, actorId: $a));
    expect(fn () => dispatchCf($t, $f, new AcknowledgeCriticalFinding(criticalFindingId: $cfId, actorId: $a)))
        ->toThrow(IllegalRadiologyCriticalFindingTransition::class);
});

test('recording / acknowledging a critical finding requires radiology.critical.manage', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture(permissions: [
        'radiology.access', 'radiology.orders.create', 'radiology.orders.accept', 'radiology.studies.perform',
        'radiology.reports.create', 'radiology.reports.verify',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId] = seedVerifiedRadiologyReport($t, $f, $a);

    expect(fn () => dispatchCf($t, $f, new RecordCriticalFinding(criticalFindingId: (string) Str::uuid7(), reportId: $reportId, actorId: $a, findingSummary: 'x', severity: 'CRITICAL')))
        ->toThrow(AuthorizationException::class);
});

test('GetCriticalFinding returns the finding for an authorized caller', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = radiologyFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $a = (string) $user->id;

    ['reportId' => $reportId] = seedReleasedRadiologyReport($t, $f, $a);
    $cfId = (string) Str::uuid7();
    dispatchCf($t, $f, new RecordCriticalFinding(criticalFindingId: $cfId, reportId: $reportId, actorId: $a, findingSummary: 'Aortic dissection.', severity: 'CRITICAL'));

    $one = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetCriticalFinding(criticalFindingId: $cfId)));
    expect($one->criticalFindingId)->toBe($cfId)->and($one->findingSummary)->toBe('Aortic dissection.');
});
