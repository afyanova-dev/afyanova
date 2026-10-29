<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;

final class PendingAdministrativeAccess
{
    public function messageFor(User $user): ?string
    {
        if ($user->tenant_id === null) {
            return null;
        }

        return TenantContextScope::run((string) $user->tenant_id, null, static function () use ($user): ?string {
            $hasActiveAssignment = DB::table('idn_access_assignments as assignment')
                ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
                ->where('assignment.tenant_id', $user->tenant_id)
                ->where('assignment.user_id', $user->getKey())
                ->where('assignment.status', 'ACTIVE')
                ->where('role.status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('assignment.effective_from')
                    ->orWhere('assignment.effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                    ->orWhere('assignment.effective_to', '>=', now()))
                ->exists();
            if ($hasActiveAssignment) {
                return null;
            }

            $pendingRequests = DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $user->tenant_id)
                ->where('target_user_id', $user->getKey())
                ->where('status', 'PENDING')
                ->where('action', 'PROMOTE')
                ->get(['role_key', 'request_origin'])
                ->unique('role_key')
                ->all();
            if ($pendingRequests === []) {
                return null;
            }

            $roleNames = array_map(
                static fn (object $request): string => FacilityUserRoleCatalog::label((string) $request->role_key)
                    ?? (string) $request->role_key,
                $pendingRequests,
            );
            $awaitingInviter = collect($pendingRequests)
                ->every(static fn (object $request): bool => $request->request_origin === 'FACILITY_INVITATION');
            $approver = $awaitingInviter
                ? 'the administrator who invited you'
                : 'an authorized administrator';

            return 'Your '.implode(' and ', $roleNames).' access request is awaiting approval by '.$approver.'. You can sign in after it is approved. Please contact that administrator for help.';
        });
    }
}
