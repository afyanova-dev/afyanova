<?php

declare(strict_types=1);

use App\Shared\Tenancy\SystemContext;
use App\Shared\Tenancy\SystemOperation;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mark existing pending Facility Admin requests that were created by the
 * Facility Admin invitation flow. New requests are marked at setup completion;
 * this backfill unblocks requests that were already pending when the inviting
 * Tenant Admin self-approval path was introduced.
 */
return new class extends Migration
{
    public function up(): void
    {
        SystemContext::run(
            SystemOperation::Migration,
            'Backfill Facility Admin invitation promotion origins.',
            function (): void {
                foreach (DB::table('shared_tenants')->orderBy('id')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId): void {
                        $pending = DB::table('idn_admin_role_promotion_requests')
                            ->where('tenant_id', $tenantId)
                            ->where('role_key', 'facility_admin')
                            ->where('action', 'PROMOTE')
                            ->where('status', 'PENDING')
                            ->whereNull('request_origin')
                            ->get(['id', 'target_user_id', 'facility_id', 'requested_by']);

                        foreach ($pending as $request) {
                            $isInvitation = DB::table('aut_admin_bootstrap_tokens')
                                ->where('tenant_id', $tenantId)
                                ->where('user_id', $request->target_user_id)
                                ->where('facility_id', $request->facility_id)
                                ->where('requested_by', $request->requested_by)
                                ->whereNotNull('consumed_at')
                                ->where(function ($query): void {
                                    $query->where('purpose', 'FACILITY_ADMIN')
                                        ->orWhere(function ($generic): void {
                                            $generic->where('purpose', 'FACILITY_ROLE')
                                                ->where('role_key', 'facility_admin');
                                        });
                                })
                                ->exists();

                            if (! $isInvitation) {
                                continue;
                            }

                            DB::table('idn_admin_role_promotion_requests')
                                ->where('id', $request->id)
                                ->update([
                                    'request_origin' => 'FACILITY_INVITATION',
                                    'updated_at' => now(),
                                ]);
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
            'Clear Facility Admin invitation promotion origins.',
            function (): void {
                foreach (DB::table('shared_tenants')->orderBy('id')->pluck('id') as $tenantId) {
                    TenantContextScope::run((string) $tenantId, null, function () use ($tenantId): void {
                        DB::table('idn_admin_role_promotion_requests')
                            ->where('tenant_id', $tenantId)
                            ->where('request_origin', 'FACILITY_INVITATION')
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
