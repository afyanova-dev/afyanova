<?php

declare(strict_types=1);

use App\Domains\Procurement\Domain\Model\Supplier;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Procurement workspace → Suppliers (Shape B).
 *
 * The screen is only the outer layer: `RegisterSupplier`, `ChangeSupplierStatus`
 * and `BlacklistSupplier` already existed and were tested at the bus. These
 * tests pin the HTTP wiring — who may read, who may write, how the form input
 * is normalised, and that the lifecycle guard still refuses illegal moves.
 */
it('renders the suppliers screen with the lifecycle the server allows', function (): void {
    ['tenant' => $tenant] = procurementFixture();
    $t = (string) $tenant->id;

    seedSupplier($t, ['code' => 'S-1', 'name' => 'Acme Medical', 'categories' => ['CONSUMABLES']]);

    $this->get('/workspaces/procurement?section=suppliers')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Procurement/Index')
            ->where('section', 'suppliers')
            ->where('canManageSuppliers', true)
            ->has('suppliers', 1)
            ->where('suppliers.0.code', 'S-1')
            ->where('suppliers.0.name', 'Acme Medical')
            ->where('suppliers.0.categories', ['CONSUMABLES'])
            ->where('suppliers.0.licenceExpiryStatus', 'MISSING')
            ->where('suppliers.0.status', Supplier::STATUS_ACTIVE)
            ->where('suppliers.0.allowedStatuses', ['SUSPENDED', 'INACTIVE', 'BLACKLISTED'])
            ->where('supplierStatuses', ['ACTIVE', 'SUSPENDED', 'INACTIVE', 'BLACKLISTED']));
});

it('captures licence details and allows supplier managers to update them', function (): void {
    ['tenant' => $tenant] = procurementFixture();
    $t = (string) $tenant->id;

    $this->post('/workspaces/procurement/suppliers', [
        'name' => 'Licensed Medical Ltd',
        'code' => 'LIC-01',
        'licence_number' => 'LICENCE-123',
        'licence_authority' => 'TMDA',
        'licence_expiry_date' => '2027-06-30',
    ])->assertRedirect()->assertSessionHas('status');

    $supplier = TenantContextScope::run($t, null, fn () => Supplier::query()->where('code', 'LIC-01')->firstOrFail());
    expect($supplier->licence_number)->toBe('LICENCE-123')
        ->and($supplier->licence_authority)->toBe('TMDA')
        ->and($supplier->licence_expiry_date->toDateString())->toBe('2027-06-30');

    $this->patch('/workspaces/procurement/suppliers/'.$supplier->id.'/licence', [
        'licence_number' => 'LICENCE-456',
        'licence_authority' => 'TMDA',
        'licence_expiry_date' => '2020-06-30',
    ])->assertRedirect()->assertSessionHas('status');

    $fresh = TenantContextScope::run($t, null, fn () => Supplier::query()->findOrFail($supplier->id));
    expect($fresh->licence_number)->toBe('LICENCE-456')
        ->and($fresh->licence_expiry_date->toDateString())->toBe('2020-06-30')
        ->and($fresh->isOrderable())->toBeTrue();

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('action', 'procurement.supplier_licence_updated')
        ->count()))->toBe(1);
});

it('computes missing, expired, expiring-soon, and current licence warnings', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-22'));

    try {
        ['tenant' => $tenant] = procurementFixture();
        $t = (string) $tenant->id;

        seedSupplier($t, ['code' => 'LIC-CURRENT', 'name' => 'A Current', 'licence_expiry_date' => '2026-11-22']);
        seedSupplier($t, ['code' => 'LIC-EXPIRED', 'name' => 'B Expired', 'licence_expiry_date' => '2026-10-21']);
        seedSupplier($t, ['code' => 'LIC-MISSING', 'name' => 'C Missing']);
        seedSupplier($t, ['code' => 'LIC-SOON', 'name' => 'D Soon', 'licence_expiry_date' => '2026-11-21']);

        $this->get('/workspaces/procurement?section=suppliers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('suppliers.0.licenceExpiryStatus', 'CURRENT')
                ->where('suppliers.1.licenceExpiryStatus', 'EXPIRED')
                ->where('suppliers.2.licenceExpiryStatus', 'MISSING')
                ->where('suppliers.3.licenceExpiryStatus', 'EXPIRING_SOON'));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

it('requires procurement access to open the workspace', function (): void {
    procurementFixture(['procurement.suppliers.manage']);

    $this->get('/workspaces/procurement')->assertForbidden();
});

it('lets a supplier manager register a supplier, normalising code and categories', function (): void {
    ['tenant' => $tenant] = procurementFixture();
    $t = (string) $tenant->id;

    $this->post('/workspaces/procurement/suppliers', [
        'name' => '  Acme Medical  ',
        'code' => 'acme-01',
        'categories' => 'consumables, Pharma , ,consumables',
        'contact_name' => 'Neema Mushi',
        'contact_phone' => '+255 700 000 000',
        'contact_email' => 'orders@acme.test',
        'payment_terms' => 'NET 30',
    ])->assertRedirect()->assertSessionHas('status');

    $supplier = TenantContextScope::run($t, null, fn () => Supplier::query()->first());

    expect($supplier)->not->toBeNull()
        ->and($supplier->code)->toBe('ACME-01')
        ->and($supplier->name)->toBe('Acme Medical')
        ->and($supplier->categories)->toBe(['CONSUMABLES', 'PHARMA'])
        ->and($supplier->contact_email)->toBe('orders@acme.test')
        ->and($supplier->status)->toBe(Supplier::STATUS_ACTIVE);

    expect(Tenancy::run($t, fn () => DB::table('shared_audit_log')
        ->where('action', 'procurement.supplier_registered')
        ->count()))->toBe(1);
});

it('reports a duplicate supplier code as a form error instead of a server error', function (): void {
    ['tenant' => $tenant] = procurementFixture();
    seedSupplier((string) $tenant->id, ['code' => 'ACME-01', 'name' => 'Acme Medical']);

    $this->post('/workspaces/procurement/suppliers', [
        'name' => 'Impostor Ltd',
        'code' => 'acme-01',
    ])->assertSessionHasErrors('supplier');

    expect(TenantContextScope::run((string) $tenant->id, null, fn () => Supplier::query()->count()))->toBe(1);
});

it('refuses writes from a reader who may only view procurement', function (): void {
    procurementFixture(['procurement.access']);

    $this->post('/workspaces/procurement/suppliers', [
        'name' => 'Acme Medical',
        'code' => 'ACME-01',
    ])->assertForbidden();

    $this->patch('/workspaces/procurement/suppliers/'.Str::uuid7().'/status', [
        'target_status' => 'SUSPENDED',
        'reason' => 'Late deliveries',
    ])->assertForbidden();

    $this->patch('/workspaces/procurement/suppliers/'.Str::uuid7().'/licence', [
        'licence_number' => 'LIC-1',
    ])->assertForbidden();
});

it('changes a supplier status with a recorded reason', function (): void {
    ['tenant' => $tenant] = procurementFixture();
    $t = (string) $tenant->id;
    $supplier = seedSupplier($t, ['code' => 'S-1', 'name' => 'Acme Medical']);

    $this->patch('/workspaces/procurement/suppliers/'.$supplier->id.'/status', [
        'target_status' => 'suspended',
        'reason' => 'Late deliveries',
    ])->assertRedirect()->assertSessionHas('status');

    $fresh = TenantContextScope::run($t, null, fn () => Supplier::query()->find($supplier->id));

    expect($fresh->status)->toBe(Supplier::STATUS_SUSPENDED)
        ->and($fresh->status_reason)->toBe('Late deliveries');
});

it('blacklists through the same endpoint and keeps it terminal', function (): void {
    ['tenant' => $tenant] = procurementFixture();
    $t = (string) $tenant->id;
    $supplier = seedSupplier($t, ['code' => 'S-1', 'name' => 'Acme Medical']);

    $this->patch('/workspaces/procurement/suppliers/'.$supplier->id.'/status', [
        'target_status' => 'BLACKLISTED',
        'reason' => 'Repeated fraud',
    ])->assertRedirect();

    expect(TenantContextScope::run($t, null, fn () => Supplier::query()->find($supplier->id)->status))
        ->toBe(Supplier::STATUS_BLACKLISTED);

    $this->patch('/workspaces/procurement/suppliers/'.$supplier->id.'/status', [
        'target_status' => 'ACTIVE',
        'reason' => 'Second chance',
    ])->assertSessionHasErrors('supplier');

    expect(TenantContextScope::run($t, null, fn () => Supplier::query()->find($supplier->id)->status))
        ->toBe(Supplier::STATUS_BLACKLISTED);
});

it('never reaches another tenant supplier', function (): void {
    ['tenant' => $tenant] = procurementFixture();
    $t = (string) $tenant->id;

    ['tenant' => $otherTenant] = makeTenantWithUser([]);
    $foreign = seedSupplier((string) $otherTenant->id, ['code' => 'X-1', 'name' => 'Foreign Ltd']);

    $this->patch('/workspaces/procurement/suppliers/'.$foreign->id.'/status', [
        'target_status' => 'SUSPENDED',
        'reason' => 'Cross-tenant attempt',
    ])->assertRedirect()->assertSessionHasErrors('supplier');

    $this->patch('/workspaces/procurement/suppliers/'.$foreign->id.'/licence', [
        'licence_number' => 'LIC-ATTACK',
        'licence_authority' => 'Unknown',
        'licence_expiry_date' => '2030-01-01',
    ])->assertRedirect()->assertSessionHasErrors('supplier');

    expect(TenantContextScope::run($t, null, fn () => Supplier::query()->count()))->toBe(0)
        ->and(TenantContextScope::run((string) $otherTenant->id, null, fn () => Supplier::query()->find($foreign->id)->status))
        ->toBe(Supplier::STATUS_ACTIVE);
});
