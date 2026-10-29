<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\AddBillingService;
use App\Domains\Billing\Application\Command\EndTariff;
use App\Domains\Billing\Application\Command\SetBillingServiceStatus;
use App\Domains\Billing\Application\Query\GetBillingService;
use App\Domains\Billing\Application\Query\GetEffectiveTariff;
use App\Domains\Billing\Application\Query\GetPriceList;
use App\Domains\Billing\Application\Query\SearchBillingServices;
use App\Domains\Billing\Domain\Exception\BillingServiceCodeTaken;
use App\Domains\Billing\Domain\Exception\BillingServiceInactive;
use App\Domains\Billing\Domain\Exception\IllegalTariffTransition;
use App\Domains\Billing\Domain\Exception\TariffChangeConflict;
use App\Domains\Billing\Domain\Exception\TariffEffectiveDateNotAfterCurrent;
use App\Domains\Billing\Domain\Model\Tariff;
use App\Domains\Billing\Domain\ValueObject\TariffStatus;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string}
 */
function catalogSetup(): array
{
    $fixture = billingFixture();

    return [
        't' => (string) $fixture['tenant']->id,
        'f' => (string) $fixture['facility']->id,
        'a' => (string) $fixture['user']->id,
    ];
}

test('AddBillingService → GetBillingService; a duplicate code is refused', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = catalogSetup();

    $id = billingAddService($t, $f, $a, 'CONSULT-GP', 'CONSULTATION');

    $summary = queryBilling($t, $f, new GetBillingService($id));
    expect($summary->code)->toBe('CONSULT-GP')
        ->and($summary->category)->toBe('CONSULTATION')
        ->and($summary->status)->toBe('ACTIVE');

    expect(fn () => dispatchBilling($t, $f, new AddBillingService(
        billingServiceId: (string) Str::uuid7(), actorId: $a, code: 'CONSULT-GP', name: 'dup', category: 'OTHER',
    )))->toThrow(BillingServiceCodeTaken::class);

    expect(queryBilling($t, $f, new SearchBillingServices(category: 'CONSULTATION')))->toHaveCount(1);
});

test('SetTariff (tenant default) then a facility override — GetEffectiveTariff resolves the right scope', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = catalogSetup();
    $svc = billingAddService($t, $f, $a, 'XRAY');

    billingSetTariff($t, $f, $a, $svc, null, '10000', '2026-01-01');       // tenant default
    billingSetTariff($t, $f, $a, $svc, $f, '14000', '2026-01-01');         // facility override

    $atFacility = queryBilling($t, $f, new GetEffectiveTariff($svc, $f, '2026-03-01'));
    expect($atFacility->resolvedScope)->toBe(TariffStatus::SCOPE_FACILITY)
        ->and($atFacility->amount)->toBe('14000.00')
        ->and($atFacility->serviceStatus)->toBe('ACTIVE');

    $atOther = queryBilling($t, $f, new GetEffectiveTariff($svc, (string) Str::uuid7(), '2026-03-01'));
    expect($atOther->resolvedScope)->toBe(TariffStatus::SCOPE_TENANT_DEFAULT)
        ->and($atOther->amount)->toBe('10000.00');

    $tenantWide = queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-03-01'));
    expect($tenantWide->resolvedScope)->toBe(TariffStatus::SCOPE_TENANT_DEFAULT);
});

test('a second SetTariff supersedes — the prior row is closed (exclusive), resolution partitions time', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = catalogSetup();
    $svc = billingAddService($t, $f, $a, 'BED-GEN', 'BED_DAY');

    billingSetTariff($t, $f, $a, $svc, null, '20000', '2026-01-01');
    billingSetTariff($t, $f, $a, $svc, null, '25000', '2026-06-01');

    expect(queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-05-31'))->amount)->toBe('20000.00');
    expect(queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-06-01'))->amount)->toBe('25000.00'); // effective_to exclusive
    expect(queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-07-01'))->amount)->toBe('25000.00');

    $open = Tenancy::run($t, fn () => DB::table('bill_tariffs')
        ->where('billing_service_id', $svc)->whereNull('effective_to')->count());
    expect($open)->toBe(1);

    expect(fn () => billingSetTariff($t, $f, $a, $svc, null, '30000', '2026-06-01'))
        ->toThrow(TariffEffectiveDateNotAfterCurrent::class);
});

test('SetTariff against an INACTIVE service is refused; deactivation does not touch tariff rows', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = catalogSetup();
    $svc = billingAddService($t, $f, $a, 'DRESS');
    billingSetTariff($t, $f, $a, $svc, null, '3000', '2026-01-01');

    dispatchBilling($t, $f, new SetBillingServiceStatus(billingServiceId: $svc, actorId: $a, status: 'INACTIVE'));

    $res = queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-03-01'));
    expect($res->amount)->toBe('3000.00')->and($res->serviceStatus)->toBe('INACTIVE');

    expect(fn () => billingSetTariff($t, $f, $a, $svc, null, '3500', '2026-06-01'))
        ->toThrow(BillingServiceInactive::class);

    dispatchBilling($t, $f, new SetBillingServiceStatus(billingServiceId: $svc, actorId: $a, status: 'ACTIVE'));
    billingSetTariff($t, $f, $a, $svc, null, '3500', '2026-06-01');
    expect(queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-07-01'))->amount)->toBe('3500.00');
});

test('an unpriced service resolves to NONE (data, not an error); EndTariff makes a scope unpriced onward', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = catalogSetup();
    $svc = billingAddService($t, $f, $a, 'NEW-SVC');

    $unpriced = queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-03-01'));
    expect($unpriced->resolvedScope)->toBe(TariffStatus::SCOPE_NONE)
        ->and($unpriced->amount)->toBeNull()
        ->and($unpriced->tariffRowId)->toBeNull();

    billingSetTariff($t, $f, $a, $svc, null, '7000', '2026-01-01');
    expect(queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-03-01'))->amount)->toBe('7000.00');

    dispatchBilling($t, $f, new EndTariff(actorId: $a, billingServiceId: $svc, facilityId: null, effectiveTo: '2026-06-01'));
    expect(queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-05-31'))->amount)->toBe('7000.00');
    expect(queryBilling($t, $f, new GetEffectiveTariff($svc, null, '2026-06-01'))->resolvedScope)->toBe(TariffStatus::SCOPE_NONE);

    expect(fn () => dispatchBilling($t, $f, new EndTariff(actorId: $a, billingServiceId: $svc, facilityId: null, effectiveTo: '2026-07-01')))
        ->toThrow(IllegalTariffTransition::class);

    $ended = Tenancy::run($t, fn () => DB::table('bill_tariffs')->where('billing_service_id', $svc)->value('status'));
    expect($ended)->toBe(Tariff::STATUS_ENDED);
});

test('GetPriceList returns one entry per service with serviceStatus', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = catalogSetup();
    $s1 = billingAddService($t, $f, $a, 'A1');
    $s2 = billingAddService($t, $f, $a, 'A2', 'LABORATORY');
    billingSetTariff($t, $f, $a, $s1, null, '1000', '2026-01-01');
    billingSetTariff($t, $f, $a, $s1, $f, '1200', '2026-01-01');
    dispatchBilling($t, $f, new SetBillingServiceStatus(billingServiceId: $s2, actorId: $a, status: 'INACTIVE'));

    $list = collect(queryBilling($t, $f, new GetPriceList(facilityId: $f, onDate: '2026-03-01')))
        ->keyBy('billingServiceId');

    expect($list)->toHaveCount(2)
        ->and($list[$s1]->resolvedScope)->toBe(TariffStatus::SCOPE_FACILITY)
        ->and($list[$s1]->amount)->toBe('1200.00')
        ->and($list[$s2]->resolvedScope)->toBe(TariffStatus::SCOPE_NONE)
        ->and($list[$s2]->serviceStatus)->toBe('INACTIVE');
});

test('the partial unique index forbids a second open tariff row for one scope', function () {
    ['t' => $t, 'f' => $f, 'a' => $a] = catalogSetup();
    $svc = billingAddService($t, $f, $a, 'RACE');
    billingSetTariff($t, $f, $a, $svc, null, '9000', '2026-01-01');

    expect(fn () => Tenancy::run($t, fn () => DB::table('bill_tariffs')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $t, 'billing_service_id' => $svc, 'facility_id' => $t,
        'amount' => '9500.0000', 'currency' => 'TZS', 'effective_from' => '2026-02-01', 'effective_to' => null,
        'status' => 'CURRENT', 'occurred_at' => now(), 'recorded_at' => now(), 'version' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);

    expect(class_exists(TariffChangeConflict::class))->toBeTrue();
});

test('a user without billing.catalog.manage cannot add a service; the tables are tenant-isolated', function () {
    ['t' => $t, 'f' => $f] = catalogSetup();

    $reader = billingUserWithKeys($t, $f, ['billing.access']);
    test()->actingAs($reader);

    expect(fn () => dispatchBilling($t, $f, new AddBillingService(
        billingServiceId: (string) Str::uuid7(), actorId: (string) $reader->id, code: 'NOPE', name: 'nope', category: 'OTHER',
    )))->toThrow(AuthorizationException::class);

    foreach (['bill_billing_services', 'bill_tariffs'] as $table) {
        expect(DB::table('pg_policies')->where('tablename', $table)->pluck('policyname')->all())
            ->toContain('tenant_isolation');
    }
});
