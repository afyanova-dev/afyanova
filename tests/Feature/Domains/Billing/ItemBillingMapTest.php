<?php

declare(strict_types=1);

use App\Domains\Billing\Application\Command\DeactivateItemBillingMap;
use App\Domains\Billing\Application\Command\MapItemToBillingService;
use App\Domains\Billing\Application\Command\SetBillingServiceStatus;
use App\Domains\Billing\Application\Query\GetItemBillingMap;
use App\Domains\Billing\Application\Query\SearchItemBillingMap;
use App\Domains\Billing\Domain\Exception\BillingServiceInactive;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{t: string, f: string, a: string, svc: string, item: string}
 */
function mapSetup(): array
{
    $fixture = billingFixture();
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;
    $a = (string) $fixture['user']->id;

    return [
        't' => $t, 'f' => $f, 'a' => $a,
        'svc' => billingAddService($t, $f, $a, 'MED-GENERIC', 'MEDICATION'),
        'item' => (string) Str::uuid7(),
    ];
}

test('MapItemToBillingService → GetItemBillingMap returns the active mapping', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item] = mapSetup();

    billingMapItem($t, $f, $a, $item, $svc);

    $map = queryBilling($t, $f, new GetItemBillingMap($item));
    expect($map)->not->toBeNull()
        ->and($map->invItemId)->toBe($item)
        ->and($map->billingServiceId)->toBe($svc)
        ->and($map->active)->toBeTrue();

    expect(queryBilling($t, $f, new GetItemBillingMap((string) Str::uuid7())))->toBeNull();
});

test('re-mapping an item supersedes the prior mapping (one active, both in the search)', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item] = mapSetup();
    $svc2 = billingAddService($t, $f, $a, 'MED-BRAND', 'MEDICATION');

    billingMapItem($t, $f, $a, $item, $svc);
    billingMapItem($t, $f, $a, $item, $svc2);

    expect(queryBilling($t, $f, new GetItemBillingMap($item))->billingServiceId)->toBe($svc2);

    $all = collect(queryBilling($t, $f, new SearchItemBillingMap));
    expect($all)->toHaveCount(2)
        ->and($all->where('active', true))->toHaveCount(1);

    $openRows = Tenancy::run($t, fn () => DB::table('bill_item_billing_map')
        ->where('inv_item_id', $item)->where('active', true)->count());
    expect($openRows)->toBe(1);
});

test('MapItemToBillingService is refused for an INACTIVE or unknown billing service', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item] = mapSetup();

    dispatchBilling($t, $f, new SetBillingServiceStatus(billingServiceId: $svc, actorId: $a, status: 'INACTIVE'));
    expect(fn () => billingMapItem($t, $f, $a, $item, $svc))->toThrow(BillingServiceInactive::class);

    expect(fn () => dispatchBilling($t, $f, new MapItemToBillingService(
        mapId: (string) Str::uuid7(), actorId: $a, invItemId: $item, billingServiceId: (string) Str::uuid7(),
    )))->toThrow(NotFound::class);
});

test('DeactivateItemBillingMap clears the active mapping and is idempotent', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item] = mapSetup();
    billingMapItem($t, $f, $a, $item, $svc);

    dispatchBilling($t, $f, new DeactivateItemBillingMap(actorId: $a, invItemId: $item));
    expect(queryBilling($t, $f, new GetItemBillingMap($item)))->toBeNull();

    // idempotent — no active mapping now
    dispatchBilling($t, $f, new DeactivateItemBillingMap(actorId: $a, invItemId: $item));
    expect(queryBilling($t, $f, new GetItemBillingMap($item)))->toBeNull();
});

test('the partial unique index forbids two active mappings for one item', function () {
    ['t' => $t, 'f' => $f, 'a' => $a, 'svc' => $svc, 'item' => $item] = mapSetup();
    billingMapItem($t, $f, $a, $item, $svc);

    expect(fn () => Tenancy::run($t, fn () => DB::table('bill_item_billing_map')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $t, 'inv_item_id' => $item, 'billing_service_id' => $svc,
        'active' => true, 'occurred_at' => now(), 'recorded_at' => now(), 'version' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(UniqueConstraintViolationException::class);
});

test('a user without billing.catalog.manage cannot map an item; the table is tenant-isolated', function () {
    ['t' => $t, 'f' => $f, 'svc' => $svc, 'item' => $item] = mapSetup();

    $reader = billingUserWithKeys($t, $f, ['billing.access']);
    test()->actingAs($reader);

    expect(fn () => dispatchBilling($t, $f, new MapItemToBillingService(
        mapId: (string) Str::uuid7(), actorId: (string) $reader->id, invItemId: $item, billingServiceId: $svc,
    )))->toThrow(AuthorizationException::class);

    expect(DB::table('pg_policies')->where('tablename', 'bill_item_billing_map')->pluck('policyname')->all())
        ->toContain('tenant_isolation');
});
