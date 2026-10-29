<?php

declare(strict_types=1);

use App\Domains\Procurement\Application\Command\BlacklistSupplier;
use App\Domains\Procurement\Application\Command\ChangeSupplierStatus;
use App\Domains\Procurement\Application\Command\RegisterSupplier;
use App\Domains\Procurement\Application\Command\UpdateSupplier;
use App\Domains\Procurement\Domain\Exception\IllegalSupplierTransition;
use App\Domains\Procurement\Domain\Exception\SupplierAlreadyExists;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('register creates an ACTIVE supplier; a duplicate active code is rejected', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $actor = (string) $user->id;

    $id = (string) Str::uuid7();
    dispatchProc($t, $f, new RegisterSupplier(
        supplierId: $id, actorId: $actor, code: 'acme-01', name: 'Acme Medical',
        categories: ['consumables', 'consumables', ' '],
    ));

    $supplier = TenantContextScope::run($t, null, fn () => Supplier::query()->find($id));
    expect($supplier->status)->toBe(Supplier::STATUS_ACTIVE)
        ->and($supplier->code)->toBe('ACME-01')
        ->and($supplier->categories)->toBe(['CONSUMABLES']);

    expect(fn () => dispatchProc($t, $f, new RegisterSupplier(
        supplierId: (string) Str::uuid7(), actorId: $actor, code: 'ACME-01', name: 'Impostor',
    )))->toThrow(SupplierAlreadyExists::class);
});

test('update changes commercial details but never code / status', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $supplier = seedSupplier($t, ['code' => 'S-1', 'status' => Supplier::STATUS_ACTIVE]);

    dispatchProc($t, $f, new UpdateSupplier(
        supplierId: (string) $supplier->id, actorId: (string) $user->id,
        name: 'Renamed Ltd', paymentTerms: 'NET_45', categories: ['pharma'],
    ));

    $fresh = TenantContextScope::run($t, null, fn () => Supplier::query()->find($supplier->id));
    expect($fresh->name)->toBe('Renamed Ltd')
        ->and($fresh->payment_terms)->toBe('NET_45')
        ->and($fresh->categories)->toBe(['PHARMA'])
        ->and($fresh->code)->toBe('S-1')
        ->and($fresh->status)->toBe(Supplier::STATUS_ACTIVE)
        ->and((int) $fresh->version)->toBe(1);
});

test('blacklist is terminal — no further transition is possible, and the code frees up', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture();
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $actor = (string) $user->id;
    $supplier = seedSupplier($t, ['code' => 'DUP', 'status' => Supplier::STATUS_ACTIVE]);

    dispatchProc($t, $f, new BlacklistSupplier((string) $supplier->id, $actor, 'Repeated fraud'));

    $fresh = TenantContextScope::run($t, null, fn () => Supplier::query()->find($supplier->id));
    expect($fresh->status)->toBe(Supplier::STATUS_BLACKLISTED)
        ->and($fresh->status_reason)->toBe('Repeated fraud');

    expect(fn () => dispatchProc($t, $f, new ChangeSupplierStatus(
        (string) $supplier->id, $actor, Supplier::STATUS_ACTIVE, 'Second chance',
    )))->toThrow(IllegalSupplierTransition::class);

    $newId = (string) Str::uuid7();
    dispatchProc($t, $f, new RegisterSupplier(supplierId: $newId, actorId: $actor, code: 'DUP', name: 'Clean Co'));
    expect(TenantContextScope::run($t, null, fn () => Supplier::query()->find($newId)->status))
        ->toBe(Supplier::STATUS_ACTIVE);
});

test('changing status requires the manage key', function () {
    ['tenant' => $tenant, 'facility' => $facility, 'user' => $user] = procurementFixture(['procurement.access']);
    $t = (string) $tenant->id;
    $f = (string) $facility->id;
    $supplier = seedSupplier($t);

    expect(fn () => dispatchProc($t, $f, new ChangeSupplierStatus(
        (string) $supplier->id, (string) $user->id, Supplier::STATUS_SUSPENDED, 'x',
    )))->toThrow(AuthorizationException::class);
});
