<?php

declare(strict_types=1);

use App\Domains\Laboratory\Application\Command\AcknowledgeCriticalResult;
use App\Domains\Laboratory\Application\Command\RecordCriticalResult;
use App\Domains\Laboratory\Application\Command\RecordResult;
use App\Domains\Laboratory\Application\Command\VerifyResult;
use App\Domains\Laboratory\Application\Query\GetPendingCriticalResults;
use App\Domains\Laboratory\Contracts\Event\CriticalLabResultAcknowledged;
use App\Domains\Laboratory\Contracts\Event\CriticalLabResultIdentified;
use App\Domains\Laboratory\Domain\Exception\CriticalResultAlreadyRecorded;
use App\Domains\Laboratory\Domain\Exception\IllegalCriticalResultTransition;
use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchLabCritical(string $tenantId, ?string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

function seedVerifiedResult(string $t, string $f, string $userId): string
{
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, $userId);
    $resultId = (string) Str::uuid7();
    TenantContextScope::run($t, $f, function () use ($resultId, $userId, $specimenId, $itemId): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new RecordResult(
            resultId: $resultId, actorId: $userId, specimenId: $specimenId, orderItemId: $itemId,
            valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 7.2, unit: 'mmol/L', parameterCode: 'K',
            referenceLow: 3.5, referenceHigh: 5.1,
        ));
        $bus->dispatch(new VerifyResult(resultId: $resultId, actorId: $userId));
    });

    return $resultId;
}

test('RecordCriticalResult flags the result CRITICAL, creates an INITIATED record, emits a PHI-free event, audits', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $resultId = seedVerifiedResult($t, $f, (string) $user->id);

    $criticalId = (string) Str::uuid7();
    dispatchLabCritical($t, $f, new RecordCriticalResult(
        criticalResultId: $criticalId, actorId: (string) $user->id, resultId: $resultId,
        severity: 'panic', clinicalContext: 'K 7.2 on patient Mary Smith — phoned the ward.',
    ));

    $critical = TenantContextScope::run($t, $f, fn () => LabCriticalResult::query()->find($criticalId));
    expect($critical)->not->toBeNull()
        ->and($critical->status)->toBe(LabCriticalResult::STATUS_INITIATED)
        ->and($critical->severity)->toBe('PANIC')
        ->and((string) $critical->result_id)->toBe($resultId);

    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result->abnormal_flag)->toBe(LabResult::FLAG_CRITICAL);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', CriticalLabResultIdentified::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->toContain('PANIC')
        ->and($outbox->payload)->not->toContain('Mary Smith');

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('target_id', $criticalId)->where('action', 'laboratory.critical_result_identified')->first());
    expect($audit->reason)->toContain('phoned the ward');
});

test('AcknowledgeCriticalResult moves it to ACKNOWLEDGED and emits the event; a second acknowledge is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $resultId = seedVerifiedResult($t, $f, (string) $user->id);
    $criticalId = (string) Str::uuid7();
    dispatchLabCritical($t, $f, new RecordCriticalResult(
        criticalResultId: $criticalId, actorId: (string) $user->id, resultId: $resultId, severity: 'CRITICAL',
    ));

    dispatchLabCritical($t, $f, new AcknowledgeCriticalResult(
        criticalResultId: $criticalId, actorId: (string) $user->id, note: 'Reviewed, patient already on treatment.',
    ));

    $critical = TenantContextScope::run($t, $f, fn () => LabCriticalResult::query()->find($criticalId));
    expect($critical->status)->toBe(LabCriticalResult::STATUS_ACKNOWLEDGED)
        ->and($critical->acknowledged_by_user_id)->toBe((string) $user->id)
        ->and($critical->acknowledgement_note)->toContain('already on treatment');

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', CriticalLabResultAcknowledged::class)->count()))->toBe(1);

    expect(fn () => dispatchLabCritical($t, $f, new AcknowledgeCriticalResult(criticalResultId: $criticalId, actorId: (string) $user->id)))
        ->toThrow(IllegalCriticalResultTransition::class);
});

test('a second RecordCriticalResult for the same result is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $resultId = seedVerifiedResult($t, $f, (string) $user->id);
    dispatchLabCritical($t, $f, new RecordCriticalResult(
        criticalResultId: (string) Str::uuid7(), actorId: (string) $user->id, resultId: $resultId, severity: 'CRITICAL',
    ));

    expect(fn () => dispatchLabCritical($t, $f, new RecordCriticalResult(
        criticalResultId: (string) Str::uuid7(), actorId: (string) $user->id, resultId: $resultId, severity: 'CRITICAL',
    )))->toThrow(CriticalResultAlreadyRecorded::class);
});

test('an ENTERED (unverified) result cannot be flagged critical', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabCritical($t, $f, new RecordResult(
        resultId: $resultId, actorId: (string) $user->id, specimenId: $specimenId, orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 7.2,
    ));

    expect(fn () => dispatchLabCritical($t, $f, new RecordCriticalResult(
        criticalResultId: (string) Str::uuid7(), actorId: (string) $user->id, resultId: $resultId, severity: 'CRITICAL',
    )))->toThrow(IllegalCriticalResultTransition::class);
});

test('recording a critical result requires laboratory.critical.manage', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture(permissions: [
        'laboratory.access', 'laboratory.orders.create', 'laboratory.orders.accept',
        'laboratory.specimens.collect', 'laboratory.specimens.receive',
        'laboratory.results.enter', 'laboratory.results.verify',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $resultId = seedVerifiedResult($t, $f, (string) $user->id);

    expect(fn () => dispatchLabCritical($t, $f, new RecordCriticalResult(
        criticalResultId: (string) Str::uuid7(), actorId: (string) $user->id, resultId: $resultId, severity: 'CRITICAL',
    )))->toThrow(AuthorizationException::class);
});

test('GetPendingCriticalResults returns the unacknowledged queue for a facility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $pendingResultId = seedVerifiedResult($t, $f, (string) $user->id);
    $pendingId = (string) Str::uuid7();
    dispatchLabCritical($t, $f, new RecordCriticalResult(criticalResultId: $pendingId, actorId: (string) $user->id, resultId: $pendingResultId, severity: 'CRITICAL'));

    $ackedResultId = seedVerifiedResult($t, $f, (string) $user->id);
    $ackedId = (string) Str::uuid7();
    dispatchLabCritical($t, $f, new RecordCriticalResult(criticalResultId: $ackedId, actorId: (string) $user->id, resultId: $ackedResultId, severity: 'CRITICAL'));
    dispatchLabCritical($t, $f, new AcknowledgeCriticalResult(criticalResultId: $ackedId, actorId: (string) $user->id));

    $queue = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetPendingCriticalResults(facilityId: $f)));
    expect($queue)->toHaveCount(1)->and($queue[0]->criticalResultId)->toBe($pendingId);
});
