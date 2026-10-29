<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Billing\Presentation;

use App\Domains\Billing\Application\Command\CaptureBillableEvent;
use App\Domains\Billing\Application\Command\SetBillingServiceStatus;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContextScope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * Price-list admin presentation (Bill.2 + Bill.3a worklist).
 *
 * @return array{t: string, f: string, user: mixed}
 */
function catalogSetup(?array $permissions = null): array
{
    $fixture = billingFixture($permissions);
    $t = (string) $fixture['tenant']->id;
    $f = (string) $fixture['facility']->id;

    // The worklist resolves patient identity via Patient's bulk read, which
    // needs patient.patients.read (the seeded billing_admin carries it).
    TenantContextScope::run($t, null, function () use ($t, $fixture): void {
        $permId = DB::table('idn_permissions')->where('key', 'patient.patients.read')->value('id')
            ?? tap((string) Str::uuid7(), fn (string $id) => DB::table('idn_permissions')->insert([
                'id' => $id, 'key' => 'patient.patients.read', 'layer' => 'ACTION',
                'created_at' => now(), 'updated_at' => now(),
            ]));
        $roleId = DB::table('idn_access_assignments')->where('user_id', $fixture['user']->id)->value('role_id');
        $hasIt = DB::table('idn_permission_role')->where('role_id', $roleId)->where('permission_id', $permId)->exists();
        if (! $hasIt) {
            DB::table('idn_permission_role')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $t,
                'role_id' => $roleId, 'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    return ['t' => $t, 'f' => $f, 'user' => $fixture['user']];
}

it('redirects guests and forbids users without billing.access', function (): void {
    $this->get('/workspaces/billing/catalog')->assertRedirect('/login');

    $fx = catalogSetup(['patient.patients.read']);
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing/catalog')->assertStatus(403);
});

it('renders the catalog for authorized users', function (): void {
    $fx = catalogSetup();
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Catalog')
            ->where('facilityId', $fx['f'])
            ->where('services', []));
});

it('adds a service and refuses a duplicate code', function (): void {
    $fx = catalogSetup();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/catalog/services', [
            'code' => 'CONSULT',
            'name' => 'Consultation',
            'category' => 'CONSULTATION',
        ])
        ->assertRedirect();

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        expect(DB::table('bill_billing_services')->where('tenant_id', $fx['t'])->count())->toBe(1);
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/catalog/services', [
            'code' => 'CONSULT',
            'name' => 'Consultation again',
            'category' => 'CONSULTATION',
        ])
        ->assertSessionHasErrors(['catalog' => 'A billing service with code [CONSULT] already exists in this tenant.']);
});

it('sets a tariff and refuses an overlapping one', function (): void {
    $fx = catalogSetup();
    $actor = (string) $fx['user']->id;
    $service = TenantContextScope::run($fx['t'], $fx['f'], fn () => billingAddService($fx['t'], $fx['f'], $actor, 'CONSULT'));

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/catalog/tariffs', [
            'billing_service_id' => $service,
            'amount' => '25000.00',
            'effective_from' => '2026-01-01',
            'facility_scope' => true,
        ])
        ->assertRedirect();

    // Same start date twice = not strictly after the open row → refused.
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/catalog/tariffs', [
            'billing_service_id' => $service,
            'amount' => '30000.00',
            'effective_from' => '2026-01-01',
            'facility_scope' => true,
        ])
        ->assertSessionHasErrors(['catalog']);

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        expect(DB::table('bill_tariffs')->where('tenant_id', $fx['t'])->count())->toBe(1);
    });
});

it('deactivates a service and ends its tariff', function (): void {
    $fx = catalogSetup();
    $actor = (string) $fx['user']->id;
    $service = TenantContextScope::run($fx['t'], $fx['f'], fn () => billingAddService($fx['t'], $fx['f'], $actor, 'LAB1', 'LABORATORY'));
    TenantContextScope::run($fx['t'], $fx['f'], fn () => billingSetTariff($fx['t'], $fx['f'], $actor, $service, null, '15000.00', '2026-01-01'));

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post("/workspaces/billing/catalog/services/{$service}/status", ['status' => 'INACTIVE'])
        ->assertSessionHasNoErrors();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/catalog/tariffs/end', [
            'billing_service_id' => $service,
            'effective_to' => '2026-06-01',
            'facility_scope' => false,
        ])
        ->assertSessionHasNoErrors();

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $service): void {
        expect(DB::table('bill_billing_services')->where('tenant_id', $fx['t'])->where('id', $service)->value('status'))->toBe('INACTIVE');
    });
});

it('retries a pending event into a charge once mapped and priced', function (): void {
    $fx = catalogSetup();
    $actor = (string) $fx['user']->id;
    $patient = billingPatient($fx['t']);
    $item = (string) Str::uuid7();

    $eventId = (string) Str::uuid7();
    $dispensingId = (string) Str::uuid7();
    dispatchBilling($fx['t'], $fx['f'], new CaptureBillableEvent(
        sourceEventId: $eventId,
        dispensingId: $dispensingId,
        patientId: $patient,
        facilityId: $fx['f'],
        itemId: $item,
        quantity: '1',
        dispensedByUserId: $actor,
        occurredAt: '2026-06-01T10:00:00+00:00',
    ));
    $billableEventId = TenantContextScope::run($fx['t'], $fx['f'], fn () => (string) DB::table('bill_billable_events')
        ->where('tenant_id', $fx['t'])->where('source_ref', $dispensingId)->value('id'));

    // Visible on the worklist before mapping.
    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('pendingEvents', 1));

    $service = TenantContextScope::run($fx['t'], $fx['f'], fn () => billingAddService($fx['t'], $fx['f'], $actor, 'MED1', 'MEDICATION'));
    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor, $item, $service): void {
        billingMapItem($fx['t'], $fx['f'], $actor, $item, $service);
        billingSetTariff($fx['t'], $fx['f'], $actor, $service, null, '5000.00', '2026-01-01');
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post("/workspaces/billing/catalog/events/{$billableEventId}/retry")
        ->assertSessionHasNoErrors();

    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx): void {
        expect(DB::table('bill_charges')->where('tenant_id', $fx['t'])->count())->toBe(1);
        expect(DB::table('bill_billable_events')->where('tenant_id', $fx['t'])->where('status', 'PENDING')->count())->toBe(0);
    });
});

it('lists medications with their mapping state and maps then deactivates one', function (): void {
    $fx = catalogSetup();
    $actor = (string) $fx['user']->id;

    $item = seedInventoryItem($fx['t'], ['item_type' => 'MEDICATION']);
    // A non-medication item must never appear on the medication worklist.
    seedInventoryItem($fx['t'], ['item_type' => 'CONSUMABLE']);

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('medicationMaps', 1)
            ->where('medicationMaps.0.invItemId', (string) $item->id)
            ->where('medicationMaps.0.code', $item->code)
            ->where('medicationMaps.0.mapped', false)
            ->where('medicationMaps.0.billingServiceId', null));

    $service = TenantContextScope::run($fx['t'], $fx['f'], fn () => billingAddService($fx['t'], $fx['f'], $actor, 'MEDMAP', 'MEDICATION'));
    TenantContextScope::run($fx['t'], $fx['f'], fn () => billingSetTariff($fx['t'], $fx['f'], $actor, $service, null, '7000.00', '2026-01-01'));

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/catalog/item-maps', [
            'item_id' => (string) $item->id,
            'billing_service_id' => $service,
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('medicationMaps.0.mapped', true)
            ->where('medicationMaps.0.billingServiceId', $service)
            ->where('medicationMaps.0.serviceCode', 'MEDMAP')
            ->where('medicationMaps.0.serviceStatus', 'ACTIVE')
            ->where('medicationMaps.0.amount', '7000.00')
            ->has('itemMapHistory', 1)
            ->where('itemMapHistory.0.active', true));

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/catalog/item-maps/deactivate', ['item_id' => (string) $item->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('medicationMaps.0.mapped', false)
            ->has('itemMapHistory', 1)
            ->where('itemMapHistory.0.active', false));
});

it('keeps item-map history newest-first and shows a mapping to an inactive service as data', function (): void {
    $fx = catalogSetup();
    $actor = (string) $fx['user']->id;
    $item = seedInventoryItem($fx['t'], ['item_type' => 'MEDICATION']);

    $first = TenantContextScope::run($fx['t'], $fx['f'], fn () => billingAddService($fx['t'], $fx['f'], $actor, 'MAP-A', 'MEDICATION'));
    $second = TenantContextScope::run($fx['t'], $fx['f'], fn () => billingAddService($fx['t'], $fx['f'], $actor, 'MAP-B', 'MEDICATION'));
    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor, $item, $first, $second): void {
        billingMapItem($fx['t'], $fx['f'], $actor, (string) $item->id, $first);
        billingMapItem($fx['t'], $fx['f'], $actor, (string) $item->id, $second);
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('itemMapHistory', 2)
            ->where('itemMapHistory.0.active', true)
            ->where('itemMapHistory.0.billingServiceId', $second)
            ->where('itemMapHistory.0.serviceName', 'MAP-B service')
            ->where('itemMapHistory.1.active', false)
            ->where('itemMapHistory.1.billingServiceId', $first)
            ->where('medicationMaps.0.billingServiceId', $second));
});

it('refuses mapping to an inactive service and denies without the manage key', function (): void {
    $fx = catalogSetup();
    $actor = (string) $fx['user']->id;
    $item = seedInventoryItem($fx['t'], ['item_type' => 'MEDICATION']);

    $service = TenantContextScope::run($fx['t'], $fx['f'], fn () => billingAddService($fx['t'], $fx['f'], $actor, 'OFF1'));
    TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $service): void {
        dispatchBilling($fx['t'], $fx['f'], new SetBillingServiceStatus(
            billingServiceId: $service, actorId: (string) $fx['user']->id, status: 'INACTIVE',
        ));
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->post('/workspaces/billing/catalog/item-maps', [
            'item_id' => (string) $item->id,
            'billing_service_id' => $service,
        ])
        ->assertSessionHasErrors(['catalog']);

    $reader = makeTenantWithUser(['billing.access']);
    $this->actingAs($reader['user'])->withSession(['active_facility_id' => (string) $reader['facility']->id])
        ->post('/workspaces/billing/catalog/item-maps', [
            'item_id' => (string) $item->id,
            'billing_service_id' => $service,
        ])
        ->assertStatus(403);
});

it('grants the seeded billing admin catalog keys and keeps the cashier read-only', function (): void {
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'billing@afyanova.local')->firstOrFail();
    $cashier = User::where('email', 'cashier@afyanova.local')->firstOrFail();
    $scope = app(ScopeChecker::class);

    $facilityId = null;
    TenantContextScope::run($admin->tenant_id, null, function () use ($admin, $scope, &$facilityId): void {
        $facilityId = $scope->selectableFacilities($admin)[0];
        $perms = $scope->effectivePermissions($admin, $facilityId);
        expect($perms)->toContain('billing.catalog.manage')
            ->and($perms)->toContain('billing.charges.manage')
            ->and($perms)->not->toContain('billing.payments.manage');
    });

    $this->actingAs($admin)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/billing/catalog')->assertOk();

    // Cashier may read prices but manages nothing.
    $this->actingAs($cashier)->withSession(['active_facility_id' => $facilityId])
        ->get('/workspaces/billing/catalog')->assertOk();
    $this->actingAs($cashier)->withSession(['active_facility_id' => $facilityId])
        ->post('/workspaces/billing/catalog/services', [
            'code' => 'X9', 'name' => 'X', 'category' => 'OTHER',
        ])
        ->assertStatus(403);
});

it('shows tariff history newest-first across both scopes', function (): void {
    $fx = catalogSetup();
    $actor = (string) $fx['user']->id;
    $service = TenantContextScope::run($fx['t'], $fx['f'], function () use ($fx, $actor): string {
        $id = billingAddService($fx['t'], $fx['f'], $actor, 'HIST');
        billingSetTariff($fx['t'], $fx['f'], $actor, $id, null, '10000.00', '2026-01-01');
        billingSetTariff($fx['t'], $fx['f'], $actor, $id, $fx['f'], '12000.00', '2026-03-01');
        billingSetTariff($fx['t'], $fx['f'], $actor, $id, null, '11000.00', '2026-06-01');
        billingSetTariff($fx['t'], $fx['f'], $actor, $id, $fx['f'], '13000.00', '2026-12-01');

        return $id;
    });

    $this->actingAs($fx['user'])->withSession(['active_facility_id' => $fx['f']])
        ->get('/workspaces/billing/catalog?service_id='.$service)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Catalog')
            ->has('tariffHistory', 4)
            ->where('tariffHistory.0.amount', '13000.00')
            ->where('tariffHistory.0.resolvedScope', 'FACILITY')
            ->where('tariffHistory.0.current', false)
            ->where('tariffHistory.1.amount', '11000.00')
            ->where('tariffHistory.1.current', true));
});

it('denies catalog writers without the manage key', function (): void {
    $fx = catalogSetup();
    $reader = makeTenantWithUser(['billing.access']);
    $readerFacility = (string) $reader['facility']->id;

    $this->actingAs($reader['user'])->withSession(['active_facility_id' => $readerFacility])
        ->post('/workspaces/billing/catalog/services', [
            'code' => 'X1',
            'name' => 'X',
            'category' => 'OTHER',
        ])
        ->assertStatus(403);
});

it('does not leak another tenant catalog', function (): void {
    $a = catalogSetup();
    $b = catalogSetup();
    $actorB = (string) $b['user']->id;
    TenantContextScope::run($b['t'], $b['f'], fn () => billingAddService($b['t'], $b['f'], $actorB, 'PRIVATE'));

    $this->actingAs($a['user'])->withSession(['active_facility_id' => $a['f']])
        ->get('/workspaces/billing/catalog')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('services', []));
});
