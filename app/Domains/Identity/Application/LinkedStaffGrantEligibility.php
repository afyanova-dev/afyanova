<?php

declare(strict_types=1);

namespace App\Domains\Identity\Application;

use App\Domains\Identity\Contracts\LinkedStaffAccessGate;
use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

final class LinkedStaffGrantEligibility implements LinkedStaffAccessGate
{
    public function lockEligibleTarget(string $tenantId, string $userId): User
    {
        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->lockForUpdate()
            ->first();
        abort_unless($user instanceof User, 404, 'The user is unavailable in this tenant.');

        $staffStatus = DB::table('hr_staff_user_links as link')
            ->join('hr_staff as staff', function ($join): void {
                $join->on('staff.id', '=', 'link.staff_id')
                    ->on('staff.tenant_id', '=', 'link.tenant_id');
            })
            ->where('link.tenant_id', $tenantId)
            ->where('link.user_id', $userId)
            ->value('staff.status');

        abort_if(
            in_array($staffStatus, ['SUSPENDED', 'TERMINATED'], true),
            409,
            'Access cannot be granted while linked staff is suspended or terminated.',
        );

        return $user;
    }

    public function isBlockedByHrLifecycle(string $tenantId, string $userId): bool
    {
        return Tenancy::run($tenantId, static fn (): bool => DB::table('hr_staff_user_links as link')
            ->join('hr_staff as staff', function ($join): void {
                $join->on('staff.id', '=', 'link.staff_id')
                    ->on('staff.tenant_id', '=', 'link.tenant_id');
            })
            ->where('link.tenant_id', $tenantId)
            ->where('link.user_id', $userId)
            ->whereIn('staff.status', ['SUSPENDED', 'TERMINATED'])
            ->exists());
    }

    /**
     * A linked staff member may sign in to finish account setup, but may not
     * open operational workspaces until HR completes onboarding.
     */
    public function isPendingOnboarding(string $tenantId, string $userId): bool
    {
        return Tenancy::run($tenantId, static fn (): bool => DB::table('hr_staff_user_links as link')
            ->join('hr_staff as staff', function ($join): void {
                $join->on('staff.id', '=', 'link.staff_id')
                    ->on('staff.tenant_id', '=', 'link.tenant_id');
            })
            ->where('link.tenant_id', $tenantId)
            ->where('link.user_id', $userId)
            ->whereIn('staff.status', ['PROSPECT', 'ONBOARDING'])
            ->exists());
    }

    public function linkedStaffStatus(string $tenantId, string $userId): ?string
    {
        $status = Tenancy::run($tenantId, static fn (): ?string => DB::table('hr_staff_user_links as link')
            ->join('hr_staff as staff', function ($join): void {
                $join->on('staff.id', '=', 'link.staff_id')
                    ->on('staff.tenant_id', '=', 'link.tenant_id');
            })
            ->where('link.tenant_id', $tenantId)
            ->where('link.user_id', $userId)
            ->value('staff.status'));

        return is_string($status) ? $status : null;
    }
}
