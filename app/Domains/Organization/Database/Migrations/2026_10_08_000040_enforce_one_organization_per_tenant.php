<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tenantIds = DB::table('shared_tenants')->orderBy('id')->pluck('id');
        $tenantIdsWithMultipleOrganizations = [];

        foreach ($tenantIds as $tenantId) {
            $organizationCount = TenantContextScope::run(
                (string) $tenantId,
                null,
                static fn (): int => DB::table('org_organizations')->count(),
            );

            if ($organizationCount > 1) {
                $tenantIdsWithMultipleOrganizations[] = (string) $tenantId;
            }
        }

        if ($tenantIdsWithMultipleOrganizations !== []) {
            throw new RuntimeException(
                'Cannot enforce one organization per tenant: resolve duplicate organizations for tenant IDs '
                .implode(', ', $tenantIdsWithMultipleOrganizations)
                .' before rerunning this migration.'
            );
        }

        Schema::table('org_organizations', function (Blueprint $table): void {
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('org_organizations', function (Blueprint $table): void {
            $table->dropUnique('org_organizations_tenant_id_unique');
        });
    }
};
