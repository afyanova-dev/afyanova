<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('grants the catalog read permission to existing Tenant Admin roles idempotently', function (): void {
    $tenant = Tenant::factory()->create();
    $tenantId = (string) $tenant->id;
    $roleId = (string) Str::uuid7();

    TenantContextScope::run($tenantId, null, function () use ($tenantId, $roleId): void {
        DB::table('idn_roles')->insert([
            'id' => $roleId,
            'tenant_id' => $tenantId,
            'key' => 'tenant_admin',
            'name' => 'Tenant Administrator',
            'status' => 'ACTIVE',
            'scope_type' => 'ORG',
            'is_protected' => true,
            'version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $migration = require base_path('app/Domains/Identity/Database/Migrations/2026_11_06_175848_grant_laboratory_catalog_read_to_tenant_admins.php');
    $migration->up();
    $migration->up();

    $permissionCount = TenantContextScope::run($tenantId, null, fn (): int => DB::table('idn_permission_role as pr')
        ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
        ->where('pr.tenant_id', $tenantId)
        ->where('pr.role_id', $roleId)
        ->where('p.key', 'laboratory.catalog.read')
        ->count());

    expect($permissionCount)->toBe(1);
});
