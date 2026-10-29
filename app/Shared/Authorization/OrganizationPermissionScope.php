<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OrganizationPermissionScope
{
    /**
     * Permissions granted through active organization-scoped assignments.
     *
     * @return list<string>
     */
    public function permissions(User $user): array
    {
        if ($user->tenant_id === null) {
            return [];
        }

        $organizationIds = DB::table('org_organizations')
            ->where('tenant_id', $user->tenant_id)
            ->where('status', 'ACTIVE')
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $permissions = [];

        foreach ($organizationIds as $organizationId) {
            $permissions = [
                ...$permissions,
                ...app(ScopeChecker::class)->effectiveOrganizationPermissions($user, $organizationId),
            ];
        }

        return array_values(array_unique($permissions));
    }

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->permissions($user), true);
    }
}
