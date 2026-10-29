<?php

declare(strict_types=1);

use App\Shared\Tenancy\SystemContext;
use App\Shared\Tenancy\SystemOperation;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Mark pending facility-role requests created by account invitations.',
            function (): void {
                foreach (DB::table('shared_tenants')->orderBy('id')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId): void {
                        $pending = DB::table('idn_admin_role_promotion_requests')
                            ->where('tenant_id', $tenantId)
                            ->where('scope_type', 'FACILITY')
                            ->where('action', 'PROMOTE')
                            ->where('status', 'PENDING')
                            ->whereNull('request_origin')
                            ->get(['id', 'target_user_id', 'facility_id', 'requested_by', 'role_key']);

                        foreach ($pending as $request) {
                            $tokens = DB::table('aut_admin_bootstrap_tokens')
                                ->where('tenant_id', $tenantId)
                                ->where('user_id', $request->target_user_id)
                                ->where('facility_id', $request->facility_id)
                                ->where('requested_by', $request->requested_by)
                                ->whereNotNull('consumed_at')
                                ->whereIn('purpose', ['FACILITY_ADMIN', 'FACILITY_ROLE'])
                                ->get(['purpose', 'role_key', 'role_keys']);

                            foreach ($tokens as $token) {
                                $legacyFacilityAdminInvite = $request->role_key === 'facility_admin'
                                    && $token->purpose === 'FACILITY_ADMIN'
                                    && $token->role_key === null;
                                $roleKeys = is_string($token->role_keys)
                                    ? json_decode($token->role_keys, true)
                                    : null;
                                $genericInvite = $token->purpose === 'FACILITY_ROLE'
                                    && ($token->role_key === $request->role_key
                                        || (is_array($roleKeys) && in_array($request->role_key, $roleKeys, true)));

                                if (! $legacyFacilityAdminInvite && ! $genericInvite) {
                                    continue;
                                }

                                DB::table('idn_admin_role_promotion_requests')
                                    ->where('id', $request->id)
                                    ->update([
                                        'request_origin' => 'FACILITY_INVITATION',
                                        'updated_at' => now(),
                                    ]);
                                break;
                            }
                        }
                    });
                }
            },
        );
    }

    public function down(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Remove invitation origin from pending facility-role requests.',
            function (): void {
                foreach (DB::table('shared_tenants')->orderBy('id')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId): void {
                        DB::table('idn_admin_role_promotion_requests')
                            ->where('tenant_id', $tenantId)
                            ->where('scope_type', 'FACILITY')
                            ->where('action', 'PROMOTE')
                            ->where('status', 'PENDING')
                            ->where('request_origin', 'FACILITY_INVITATION')
                            ->where('role_key', '!=', 'facility_admin')
                            ->update([
                                'request_origin' => null,
                                'updated_at' => now(),
                            ]);
                    });
                }
            },
        );
    }
};
