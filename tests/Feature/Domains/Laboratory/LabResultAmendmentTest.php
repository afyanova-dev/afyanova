<?php

declare(strict_types=1);

use App\Domains\Laboratory\Application\Command\AmendResult;
use App\Domains\Laboratory\Application\Command\CancelResult;
use App\Domains\Laboratory\Application\Command\RecordResult;
use App\Domains\Laboratory\Application\Command\ReleaseResult;
use App\Domains\Laboratory\Application\Command\VerifyResult;
use App\Domains\Laboratory\Application\Query\GetResultAmendments;
use App\Domains\Laboratory\Contracts\Event\LabResultAmended;
use App\Domains\Laboratory\Contracts\Event\LabResultCancelled;
use App\Domains\Laboratory\Domain\Exception\IllegalLabResultTransition;
use App\Domains\Laboratory\Domain\Exception\ResultAmendmentIncomplete;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Model\LabResultAmendment;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dispatchLabAmend(string $tenantId, ?string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

function seedReleasedResult(string $t, string $f, string $userId): string
{
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, $userId);
    $resultId = (string) Str::uuid7();
    TenantContextScope::run($t, $f, function () use ($resultId, $userId, $specimenId, $itemId): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new RecordResult(
            resultId: $resultId, actorId: $userId, specimenId: $specimenId, orderItemId: $itemId,
            valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 8.1, unit: 'g/dL', parameterCode: 'HB',
            referenceLow: 12.0, referenceHigh: 16.0,
        ));
        $bus->dispatch(new VerifyResult(resultId: $resultId, actorId: $userId));
        $bus->dispatch(new ReleaseResult(resultId: $resultId, actorId: $userId));
    });

    return $resultId;
}

test('AmendResult corrects a released result, appends an append-only amendment row, flips to AMENDED, emits a PHI-free event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $resultId = seedReleasedResult($t, $f, (string) $user->id);

    dispatchLabAmend($t, $f, new AmendResult(
        resultId: $resultId, amendmentId: (string) Str::uuid7(), actorId: (string) $user->id,
        reason: 'Transcription error — analyzer printout says 12.4 for patient Jane Doe.',
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 12.4, unit: 'g/dL',
        referenceLow: 12.0, referenceHigh: 16.0,
    ));

    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result->status)->toBe(LabResult::STATUS_AMENDED)
        ->and((float) $result->value_numeric)->toBe(12.4)
        ->and($result->abnormal_flag)->toBe(LabResult::FLAG_NORMAL)
        ->and((int) $result->amendment_count)->toBe(1);

    $amendment = TenantContextScope::run($t, $f, fn () => LabResultAmendment::query()->where('result_id', $resultId)->first());
    expect($amendment)->not->toBeNull()
        ->and($amendment->amendment_type)->toBe(LabResultAmendment::TYPE_CORRECTION)
        ->and((float) $amendment->previous_value_numeric)->toBe(8.1)
        ->and((float) $amendment->new_value_numeric)->toBe(12.4)
        ->and($amendment->sequence)->toBe(1);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', LabResultAmended::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->not->toContain('Jane Doe')
        ->and($outbox->payload)->not->toContain('12.4');

    $audit = Tenancy::run($t, fn () => DB::table('shared_audit_log')->where('target_id', $resultId)->where('action', 'laboratory.result_amended')->first());
    expect($audit->reason)->toContain('Transcription error');
});

test('a second amendment appends sequence 2 and keeps the result AMENDED', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $resultId = seedReleasedResult($t, $f, (string) $user->id);

    dispatchLabAmend($t, $f, new AmendResult(
        resultId: $resultId, amendmentId: (string) Str::uuid7(), actorId: (string) $user->id, reason: 'First correction.',
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 12.4,
    ));
    dispatchLabAmend($t, $f, new AmendResult(
        resultId: $resultId, amendmentId: (string) Str::uuid7(), actorId: (string) $user->id, reason: 'Second correction.',
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 12.9,
    ));

    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result->status)->toBe(LabResult::STATUS_AMENDED)
        ->and((int) $result->amendment_count)->toBe(2)
        ->and((float) $result->value_numeric)->toBe(12.9);

    $history = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetResultAmendments(resultId: $resultId)));
    expect($history)->toHaveCount(2)
        ->and($history[1]->sequence)->toBe(2);
});

test('CancelResult invalidates a result and records the reason + replacement link', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $resultId = seedReleasedResult($t, $f, (string) $user->id);
    $replacementId = (string) Str::uuid7();

    dispatchLabAmend($t, $f, new CancelResult(
        resultId: $resultId, amendmentId: (string) Str::uuid7(), actorId: (string) $user->id,
        reason: 'Wrong specimen — analytical error.', replacementResultId: $replacementId,
    ));

    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result->status)->toBe(LabResult::STATUS_CANCELLED)
        ->and($result->cancellation_reason)->toContain('Wrong specimen');

    $amendment = TenantContextScope::run($t, $f, fn () => LabResultAmendment::query()->where('result_id', $resultId)->first());
    expect($amendment->amendment_type)->toBe(LabResultAmendment::TYPE_CANCELLATION)
        ->and((string) $amendment->replacement_result_id)->toBe($replacementId);

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', LabResultCancelled::class)->count()))->toBe(1);
});

test('AmendResult requires a reason and rejects an unfinalized result', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabAmend($t, $f, new RecordResult(
        resultId: $resultId, actorId: (string) $user->id, specimenId: $specimenId, orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 8.1,
    )); // ENTERED, not verified

    expect(fn () => new AmendResult(
        resultId: $resultId, amendmentId: (string) Str::uuid7(), actorId: (string) $user->id, reason: '  ',
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 9.0,
    ))->toThrow(ResultAmendmentIncomplete::class);

    expect(fn () => dispatchLabAmend($t, $f, new AmendResult(
        resultId: $resultId, amendmentId: (string) Str::uuid7(), actorId: (string) $user->id, reason: 'fix',
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 9.0,
    )))->toThrow(IllegalLabResultTransition::class);
});

test('lab_result_amendments is append-only — UPDATE / DELETE affect zero rows and the RESTRICTIVE policies exist', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $resultId = seedReleasedResult($t, $f, (string) $user->id);
    dispatchLabAmend($t, $f, new AmendResult(
        resultId: $resultId, amendmentId: (string) Str::uuid7(), actorId: (string) $user->id, reason: 'Correction.',
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 12.4,
    ));

    $policies = DB::table('pg_policies')->where('tablename', 'lab_result_amendments')->pluck('policyname')->all();
    expect($policies)->toContain('lab_result_amendments_no_update')
        ->and($policies)->toContain('lab_result_amendments_no_delete');

    $raw = DB::connection('pgsql_raw');
    $amendmentId = Tenancy::run($t, fn () => DB::table('lab_result_amendments')->where('result_id', $resultId)->value('id'));

    $raw->transaction(function () use ($raw, $t, $amendmentId): void {
        $raw->statement("select set_config('app.tenant_id', ?, true)", [$t]);
        expect($raw->table('lab_result_amendments')->where('id', $amendmentId)->update(['reason' => 'tampered']))->toBe(0);
        expect($raw->table('lab_result_amendments')->where('id', $amendmentId)->delete())->toBe(0);
    });

    expect(Tenancy::run($t, fn () => DB::table('lab_result_amendments')->where('id', $amendmentId)->value('reason')))
        ->not->toBe('tampered');
});
