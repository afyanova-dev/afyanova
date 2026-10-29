<?php

declare(strict_types=1);

namespace App\Domains\Identity;

use App\Domains\Identity\Application\IdentityApiService;
use App\Domains\Identity\Application\LinkedStaffAccessService;
use App\Domains\Identity\Application\LinkedStaffGrantEligibility;
use App\Domains\Identity\Console\ManageAssignmentCommand;
use App\Domains\Identity\Console\ManageRoleCommand;
use App\Domains\Identity\Console\ManageStaffIdentityCommand;
use App\Domains\Identity\Console\SyncRolePermissionsCommand;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Identity\Contracts\LinkedStaffAccessGate;
use App\Domains\Identity\Contracts\LinkedStaffLifecycleApi;
use App\Shared\Authorization\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Identity & RBAC module (docs/00-foundation/03-identity-rbac.md), prefix `idn_`.
 *
 * PHASE 3: the full role / access-assignment / staff-identity model + the read
 * port. The enforcement mechanism (Gate resolver, BasePolicy, ScopeChecker)
 * lives in the shared kernel. Dynamic-SoD enforcement, delegation-as-entity and
 * the RBAC admin UI are PHASE 4 / PHASE 16.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(IdentityApi::class, IdentityApiService::class);
        $this->app->bind(LinkedStaffAccessGate::class, LinkedStaffGrantEligibility::class);
        $this->app->bind(LinkedStaffLifecycleApi::class, LinkedStaffAccessService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ManageRoleCommand::class,
                ManageAssignmentCommand::class,
                ManageStaffIdentityCommand::class,
                SyncRolePermissionsCommand::class,
            ]);
        }
    }
}
