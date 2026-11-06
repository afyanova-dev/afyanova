<?php

declare(strict_types=1);

namespace App\Domains\Identity\Presentation\Http\Controller;

use App\Domains\Identity\Contracts\IdentityApi;
use App\Models\User;
use App\Shared\Authorization\OrganizationPermissionScope;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AdminRoleCatalogController
{
    public function __invoke(
        Request $request,
        TenantContext $tenantContext,
        OrganizationPermissionScope $organizationPermissions,
        IdentityApi $identityApi,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless(
            $user->can('identity.roles.read')
                || $organizationPermissions->allows($user, 'identity.roles.read'),
            403,
        );

        return Inertia::render('Admin/Identity/RoleCatalog', [
            'roles' => $identityApi->roleCatalog(
                $tenantContext->tenantId(),
                $request->integer('page', 1),
            ),
        ]);
    }
}
