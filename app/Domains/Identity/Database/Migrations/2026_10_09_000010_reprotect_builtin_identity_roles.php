<?php

declare(strict_types=1);

use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const BUILTIN_ROLE_KEYS = [
        'facility_admin',
        'receptionist',
        'cashier',
        'billing_admin',
        'pharmacist',
        'doctor',
        'nurse',
        'tenant_admin',
    ];

    public function up(): void
    {
        DB::table('shared_tenants')->orderBy('id')->pluck('id')->each(
            static function (string $tenantId): void {
                TenantContextScope::run($tenantId, null, static function () use ($tenantId): void {
                    DB::table('idn_roles')
                        ->where('tenant_id', $tenantId)
                        ->whereIn('key', self::BUILTIN_ROLE_KEYS)
                        ->update(['is_protected' => true, 'updated_at' => now()]);
                });
            }
        );
    }

    public function down(): void
    {
        // Protected built-in roles must not become mutable during rollback.
    }
};
