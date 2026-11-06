<?php

declare(strict_types=1);

use App\Domains\Laboratory\Application\Command\RecordQcRun;
use App\Domains\Laboratory\Application\Command\RecordResult;
use App\Domains\Laboratory\Application\Command\ReleaseResult;
use App\Domains\Laboratory\Application\Command\ReviewQcRun;
use App\Domains\Laboratory\Application\Command\VerifyResult;
use App\Domains\Laboratory\Application\Query\GetQcRuns;
use App\Domains\Laboratory\Contracts\Event\QcRunFailed;
use App\Domains\Laboratory\Contracts\Event\QcRunRecorded;
use App\Domains\Laboratory\Domain\Exception\IllegalQcRunTransition;
use App\Domains\Laboratory\Domain\Exception\LabResultNotReleasable;
use App\Domains\Laboratory\Domain\Model\LabFacilityInstrument;
use App\Domains\Laboratory\Domain\Model\LabFacilityInstrumentMethod;
use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Model\LabTestDefinition;
use App\Domains\Laboratory\Domain\Model\LabTestMethod;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function dispatchLabQc(string $tenantId, ?string $facilityId, object $command): mixed
{
    return TenantContextScope::run($tenantId, $facilityId, fn () => app(CommandBus::class)->dispatch($command));
}

function registerQcInstrument(
    string $tenantId,
    string $facilityId,
    string $identifier,
    ?string $testDefinitionId = null,
): string {
    return TenantContextScope::run($tenantId, $facilityId, function () use (
        $tenantId,
        $facilityId,
        $identifier,
        $testDefinitionId,
    ): string {
        $instrument = LabFacilityInstrument::register([
            'tenantId' => $tenantId,
            'facilityId' => $facilityId,
            'identifier' => $identifier,
            'name' => "Analyzer {$identifier}",
            'manufacturer' => null,
            'model' => null,
            'serialNumber' => null,
            'inventoryItemId' => null,
            'inventoryCodeSnapshot' => null,
            'inventoryNameSnapshot' => null,
        ]);
        $instrument->save();

        if ($testDefinitionId !== null) {
            $method = LabTestMethod::query()->create([
                'tenant_id' => $tenantId,
                'test_definition_id' => $testDefinitionId,
                'code' => 'QC-METHOD',
                'name' => 'QC Method',
                'active' => true,
                'version' => 1,
            ]);
            LabFacilityInstrumentMethod::query()->create([
                'tenant_id' => $tenantId,
                'instrument_id' => $instrument->id,
                'instrument_version' => $instrument->version,
                'method_id' => $method->id,
                'method_version' => $method->version,
                'method_code_snapshot' => $method->code,
                'method_name_snapshot' => $method->name,
            ]);
        }

        return (string) $instrument->id;
    });
}

/**
 * @return array{resultId: string, definitionId: string}
 */
function seedVerifiedResultForQc(string $t, string $f, string $userId): array
{
    ['specimenId' => $specimenId, 'orderItemId' => $itemId] = seedResultReadyLabOrder($t, $f, $userId);
    $definitionId = (string) TenantContextScope::run($t, $f, fn () => DB::table('lab_order_items')->where('id', $itemId)->value('test_definition_id'));
    $resultId = (string) Str::uuid7();
    TenantContextScope::run($t, $f, function () use ($resultId, $userId, $specimenId, $itemId): void {
        $bus = app(CommandBus::class);
        $bus->dispatch(new RecordResult(
            resultId: $resultId, actorId: $userId, specimenId: $specimenId, orderItemId: $itemId,
            valueType: LabResult::VALUE_TYPE_NUMERIC, valueNumeric: 5.0, unit: 'mmol/L',
        ));
        $bus->dispatch(new VerifyResult(resultId: $resultId, actorId: $userId));
    });

    return ['resultId' => $resultId, 'definitionId' => $definitionId];
}

test('RecordQcRun computes PASS and emits QcRunRecorded, no hold applied', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $instrumentId = registerQcInstrument($t, $f, 'A1');
    $qcId = (string) Str::uuid7();
    dispatchLabQc($t, $f, new RecordQcRun(
        qcRunId: $qcId, actorId: (string) $user->id, instrumentId: $instrumentId, controlMaterial: 'BIO-RAD L1',
        observedValue: 5.0, expectedLow: 4.5, expectedHigh: 5.5,
    ));

    $run = TenantContextScope::run($t, $f, fn () => LabQcRun::query()->find($qcId));
    expect($run->status)->toBe(LabQcRun::STATUS_PASS)
        ->and($run->instrument_id)->toBe($instrumentId)
        ->and($run->analyzer_ref)->toBe('A1');
    expect(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', QcRunRecorded::class)->count()))->toBe(1)
        ->and(Tenancy::run($t, fn () => DB::table('shared_outbox_events')->where('event_class', QcRunFailed::class)->count()))->toBe(0);
});

test('a failing QC run holds the facility VERIFIED results for that test, and release is blocked until review', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    ['resultId' => $resultId, 'definitionId' => $definitionId] = seedVerifiedResultForQc($t, $f, (string) $user->id);

    $instrumentId = registerQcInstrument($t, $f, 'A1', $definitionId);
    $qcId = (string) Str::uuid7();
    dispatchLabQc($t, $f, new RecordQcRun(
        qcRunId: $qcId, actorId: (string) $user->id, instrumentId: $instrumentId, controlMaterial: 'BIO-RAD L1',
        observedValue: 9.9, expectedLow: 4.5, expectedHigh: 5.5, testDefinitionId: $definitionId,
    ));

    $run = TenantContextScope::run($t, $f, fn () => LabQcRun::query()->find($qcId));
    expect($run->status)->toBe(LabQcRun::STATUS_FAIL);

    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result->release_hold_reason)->toBe('QC_FAILED');

    expect(fn () => dispatchLabQc($t, $f, new ReleaseResult(resultId: $resultId, actorId: (string) $user->id)))
        ->toThrow(LabResultNotReleasable::class);

    dispatchLabQc($t, $f, new ReviewQcRun(qcRunId: $qcId, actorId: (string) $user->id, note: 'Recalibrated, control back in range.'));

    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result->release_hold_reason)->toBeNull();

    // now release succeeds
    dispatchLabQc($t, $f, new ReleaseResult(resultId: $resultId, actorId: (string) $user->id));
    $result = TenantContextScope::run($t, $f, fn () => LabResult::query()->find($resultId));
    expect($result->status)->toBe(LabResult::STATUS_RELEASED);
});

test('reviewing a passing run, or reviewing twice, is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $instrumentId = registerQcInstrument($t, $f, 'A1');
    $passId = (string) Str::uuid7();
    dispatchLabQc($t, $f, new RecordQcRun(
        qcRunId: $passId, actorId: (string) $user->id, instrumentId: $instrumentId, controlMaterial: 'L1',
        observedValue: 5.0, expectedLow: 4.5, expectedHigh: 5.5,
    ));
    expect(fn () => dispatchLabQc($t, $f, new ReviewQcRun(qcRunId: $passId, actorId: (string) $user->id)))
        ->toThrow(IllegalQcRunTransition::class);

    $failId = (string) Str::uuid7();
    dispatchLabQc($t, $f, new RecordQcRun(
        qcRunId: $failId, actorId: (string) $user->id, instrumentId: $instrumentId, controlMaterial: 'L1',
        observedValue: 9.0, expectedLow: 4.5, expectedHigh: 5.5,
    ));
    dispatchLabQc($t, $f, new ReviewQcRun(qcRunId: $failId, actorId: (string) $user->id));
    expect(fn () => dispatchLabQc($t, $f, new ReviewQcRun(qcRunId: $failId, actorId: (string) $user->id)))
        ->toThrow(IllegalQcRunTransition::class);
});

test('recording a QC run requires laboratory.quality.manage', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture(permissions: [
        'laboratory.access', 'encounter.encounters.read', 'encounter.encounters.create',
        'encounter.encounters.update', 'patient.patients.read',
    ]);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    expect(fn () => dispatchLabQc($t, $f, new RecordQcRun(
        qcRunId: (string) Str::uuid7(), actorId: (string) $user->id, instrumentId: (string) Str::uuid7(), controlMaterial: 'L1',
        observedValue: 5.0,
    )))->toThrow(AuthorizationException::class);
});

test('GetQcRuns returns the facility QC board newest-first', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;

    $instrumentA = registerQcInstrument($t, $f, 'A1');
    $instrumentB = registerQcInstrument($t, $f, 'A2');
    dispatchLabQc($t, $f, new RecordQcRun(qcRunId: (string) Str::uuid7(), actorId: (string) $user->id, instrumentId: $instrumentA, controlMaterial: 'L1', observedValue: 5.0, expectedLow: 4.5, expectedHigh: 5.5));
    dispatchLabQc($t, $f, new RecordQcRun(qcRunId: (string) Str::uuid7(), actorId: (string) $user->id, instrumentId: $instrumentB, controlMaterial: 'L2', observedValue: 9.0, expectedLow: 4.5, expectedHigh: 5.5));

    $board = TenantContextScope::run($t, $f, fn () => app(QueryBus::class)->dispatch(new GetQcRuns(facilityId: $f)));
    expect($board)->toHaveCount(2)
        ->and(collect($board)->pluck('status')->all())->toContain(LabQcRun::STATUS_PASS, LabQcRun::STATUS_FAIL);
});

test('recording a QC run requires an active instrument registered at the facility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $instrumentId = registerQcInstrument($tenantId, $facilityId, 'A1');

    TenantContextScope::run($tenantId, $facilityId, function () use ($instrumentId): void {
        LabFacilityInstrument::query()->whereKey($instrumentId)->update(['status' => LabFacilityInstrument::STATUS_OUT_OF_SERVICE]);
    });

    expect(fn () => dispatchLabQc($tenantId, $facilityId, new RecordQcRun(
        qcRunId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        instrumentId: $instrumentId,
        controlMaterial: 'L1',
        observedValue: 5.0,
    )))->toThrow(NotFound::class);
});

test('recording a test-specific QC run requires instrument method compatibility', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $supportedTestId = (string) seedLabTestDefinition($tenantId)->id;
    $unsupportedTestId = (string) seedLabTestDefinition($tenantId, ['code' => 'UNSUPPORTED-QC'])->id;
    $instrumentId = registerQcInstrument($tenantId, $facilityId, 'A1', $supportedTestId);

    expect(fn () => dispatchLabQc($tenantId, $facilityId, new RecordQcRun(
        qcRunId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        instrumentId: $instrumentId,
        controlMaterial: 'L1',
        observedValue: 5.0,
        testDefinitionId: $unsupportedTestId,
    )))->toThrow(ValidationException::class);
});

test('recording test-specific QC requires the configured method version to remain current', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $definitionId = (string) seedLabTestDefinition($tenantId, ['code' => 'STALE-METHOD-QC'])->id;
    $instrumentId = registerQcInstrument($tenantId, $facilityId, 'A1', $definitionId);

    TenantContextScope::run($tenantId, null, function () use ($definitionId): void {
        $method = LabTestMethod::query()->where('test_definition_id', $definitionId)->firstOrFail();
        $method->revise('Updated QC method', null, null, null, true);
        $method->save();
    });

    expect(fn () => dispatchLabQc($tenantId, $facilityId, new RecordQcRun(
        qcRunId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        instrumentId: $instrumentId,
        controlMaterial: 'L1',
        observedValue: 5.0,
        testDefinitionId: $definitionId,
    )))->toThrow(ValidationException::class);
});

test('recording test-specific QC requires an active available test definition', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = laboratoryFixture();
    $tenantId = (string) $tenant->id;
    $facilityId = (string) $facility->id;
    $definitionId = (string) seedLabTestDefinition($tenantId, ['code' => 'UNAVAILABLE-QC'])->id;
    $instrumentId = registerQcInstrument($tenantId, $facilityId, 'A1', $definitionId);

    TenantContextScope::run($tenantId, null, function () use ($definitionId): void {
        LabTestDefinition::query()
            ->whereKey($definitionId)
            ->update(['availability_status' => LabTestDefinition::AVAILABILITY_REFERRAL_ONLY]);
    });

    expect(fn () => dispatchLabQc($tenantId, $facilityId, new RecordQcRun(
        qcRunId: (string) Str::uuid7(),
        actorId: (string) $user->id,
        instrumentId: $instrumentId,
        controlMaterial: 'L1',
        observedValue: 5.0,
        testDefinitionId: $definitionId,
    )))->toThrow(ValidationException::class);
});
