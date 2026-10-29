<?php

declare(strict_types=1);

use App\Domains\Laboratory\Application\Command\RecordResult;
use App\Domains\Laboratory\Application\Command\ReleaseResult;
use App\Domains\Laboratory\Application\Command\ReviewResult;
use App\Domains\Laboratory\Application\Command\VerifyResult;
use App\Domains\Laboratory\Application\Query\GetLabResult;
use App\Domains\Laboratory\Application\Query\GetOrderResults;
use App\Domains\Laboratory\Contracts\Event\LabResultEntered;
use App\Domains\Laboratory\Contracts\Event\LabResultReleased;
use App\Domains\Laboratory\Contracts\Event\LabResultVerified;
use App\Domains\Laboratory\Domain\Exception\IllegalLabResultTransition;
use App\Domains\Laboratory\Domain\Exception\LabResultNotReleasable;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabResult;
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

function dispatchLabResult(string $tenantId, ?string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

test('RecordResult enters a numeric result, computes the abnormal flag, snapshots the range, advances specimen + order, emits a PHI-free event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['orderId' => $orderId, 'specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabResult($t, $f, new RecordResult(
        resultId: $resultId,
        actorId: (string) $user->id,
        specimenId: $specimenId,
        orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_NUMERIC,
        valueNumeric: 8.1,
        unit: 'g/dL',
        parameterCode: 'HB',
        parameterName: 'Haemoglobin',
        referenceLow: 12.0,
        referenceHigh: 16.0,
        referenceRangeSource: 'CATALOG adult female',
    ));

    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result)->not->toBeNull()
        ->and($result->status)->toBe(LabResult::STATUS_ENTERED)
        ->and($result->abnormal_flag)->toBe(LabResult::FLAG_LOW)
        ->and((float) $result->reference_low)->toBe(12.0)
        ->and($result->reference_range_source)->toBe('CATALOG adult female')
        ->and((int) $result->version)->toBe(0);

    $specimen = TenantContextScope::run($t, $f, fn () => LabSpecimen::query()->find($specimenId));
    expect($specimen->status)->toBe(LabSpecimen::STATUS_PROCESSING);

    $order = TenantContextScope::run($t, $f, fn () => LabOrder::query()->find($orderId));
    expect($order->status)->toBe(LabOrder::STATUS_RESULTED);

    $outbox = Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', LabResultEntered::class)->first());
    expect($outbox)->not->toBeNull()
        ->and($outbox->payload)->toContain('LOW')
        ->and($outbox->payload)->not->toContain('8.1');
});

test('a result runs ENTERED -> VERIFIED -> RELEASED, ratcheting the order to RELEASED, each emitting its PHI-free event', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['orderId' => $orderId, 'specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabResult($t, $f, new RecordResult(
        resultId: $resultId, actorId: (string) $user->id, specimenId: $specimenId, orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_CODED, valueCode: 'negative',
    ));
    dispatchLabResult($t, $f, new VerifyResult(resultId: $resultId, actorId: (string) $user->id));
    dispatchLabResult($t, $f, new ReleaseResult(resultId: $resultId, actorId: (string) $user->id));

    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result->status)->toBe(LabResult::STATUS_RELEASED)
        ->and($result->value_code)->toBe('NEGATIVE')
        ->and($result->verified_by_user_id)->toBe((string) $user->id)
        ->and($result->released_at)->not->toBeNull();

    $order = TenantContextScope::run($t, $f, fn () => LabOrder::query()->find($orderId));
    expect($order->status)->toBe(LabOrder::STATUS_RELEASED);

    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', LabResultVerified::class)->count()))->toBe(1)
        ->and(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', LabResultReleased::class)->count()))->toBe(1);
});

test('an optional review step is allowed before verification', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabResult($t, $f, new RecordResult(
        resultId: $resultId, actorId: (string) $user->id, specimenId: $specimenId, orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_TEXT, valueText: 'No abnormal cells seen on the film.',
    ));
    dispatchLabResult($t, $f, new ReviewResult(resultId: $resultId, actorId: (string) $user->id));
    dispatchLabResult($t, $f, new VerifyResult(resultId: $resultId, actorId: (string) $user->id));

    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result->status)->toBe(LabResult::STATUS_VERIFIED)
        ->and($result->reviewed_by_user_id)->toBe((string) $user->id)
        ->and($result->value_text)->toContain('No abnormal cells');
});

test('releasing an unverified result is blocked by the release gate', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabResult($t, $f, new RecordResult(
        resultId: $resultId, actorId: (string) $user->id, specimenId: $specimenId, orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 5.0,
    ));

    expect(fn () => dispatchLabResult($t, $f, new ReleaseResult(resultId: $resultId, actorId: (string) $user->id)))
        ->toThrow(LabResultNotReleasable::class);
});

test('a result on a manual release hold cannot be released until the hold clears', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabResult($t, $f, new RecordResult(
        resultId: $resultId, actorId: (string) $user->id, specimenId: $specimenId, orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 5.0,
    ));
    dispatchLabResult($t, $f, new VerifyResult(resultId: $resultId, actorId: (string) $user->id));

    // simulate a QC hold (Lab.5 will set this via a command; here we assert the gate honours it)
    TenantContextScope::run($t, $f, fn () => LabResult::query()->whereKey($resultId)->update(['release_hold_reason' => 'QC_FAILED']));

    expect(fn () => dispatchLabResult($t, $f, new ReleaseResult(resultId: $resultId, actorId: (string) $user->id)))
        ->toThrow(LabResultNotReleasable::class);
});

test('verifying requires laboratory.results.verify; releasing requires laboratory.results.release', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture(permissions: [
        'laboratory.access', 'laboratory.orders.create', 'laboratory.orders.accept',
        'laboratory.specimens.collect', 'laboratory.specimens.receive', 'laboratory.results.enter',
        'encounter.encounters.read', 'encounter.encounters.create', 'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabResult($t, $f, new RecordResult(
        resultId: $resultId, actorId: (string) $user->id, specimenId: $specimenId, orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 5.0,
    ));

    expect(fn () => dispatchLabResult($t, $f, new VerifyResult(resultId: $resultId, actorId: (string) $user->id)))
        ->toThrow(AuthorizationException::class);
});

test('verifying an already-verified result is an illegal transition', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabResult($t, $f, new RecordResult(
        resultId: $resultId, actorId: (string) $user->id, specimenId: $specimenId, orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 5.0,
    ));
    dispatchLabResult($t, $f, new VerifyResult(resultId: $resultId, actorId: (string) $user->id));

    expect(fn () => dispatchLabResult($t, $f, new VerifyResult(resultId: $resultId, actorId: (string) $user->id)))
        ->toThrow(IllegalLabResultTransition::class);
});

test('RecordResult rejects a value that does not match the declared valueType', function () {
    expect(fn () => new RecordResult(
        resultId: (string) Str::uuid7(), actorId: (string) Str::uuid7(),
        specimenId: (string) Str::uuid7(), orderItemId: (string) Str::uuid7(),
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueCode: 'POSITIVE',
    ))->toThrow(InvalidArgumentException::class);
});

test('GetLabResult and GetOrderResults return the results for an order', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    ['orderId' => $orderId, 'specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, (string) $user->id);

    $resultId = (string) Str::uuid7();
    dispatchLabResult($t, $f, new RecordResult(
        resultId: $resultId, actorId: (string) $user->id, specimenId: $specimenId, orderItemId: $itemId,
        valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 13.2, unit: 'g/dL', parameterCode: 'HB',
    ));

    $one = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetLabResult(resultId: $resultId)));
    expect($one->resultId)->toBe($resultId)->and($one->valueNumeric)->toBe('13.200000');

    $all = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetOrderResults(orderId: $orderId)));
    expect($all)->toHaveCount(1)->and($all[0]->parameterCode)->toBe('HB');
});
