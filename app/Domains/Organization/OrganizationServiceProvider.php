<?php

declare(strict_types=1);

namespace App\Domains\Organization;

use App\Domains\Organization\Application\OrganizationAdminApiService;
use App\Domains\Organization\Application\OrganizationApiService;
use App\Domains\Organization\Console\ManageCapabilityCommand;
use App\Domains\Organization\Console\ManageFacilityCommand;
use App\Domains\Organization\Console\ManageStructureCommand;
use App\Domains\Organization\Contracts\OrganizationAdminApi;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Shared\Authorization\PermissionRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Organization module (docs/00-foundation/02-organization.md,
 * 06-facility-levels-and-capabilities.md), prefix `org_`. Registered before
 * Identity (IC §2.4 example order).
 *
 * PHASE 3: structure + capability model + the read port. Write endpoints, the
 * bus-backed Api writes and the Administration workspace are PHASE 4 / PHASE 16.
 */
final class OrganizationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OrganizationApi::class, OrganizationApiService::class);
        $this->app->bind(OrganizationAdminApi::class, OrganizationAdminApiService::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');

        PermissionRegistry::add(require __DIR__.'/Authorization/permissions.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ManageFacilityCommand::class,
                ManageStructureCommand::class,
                ManageCapabilityCommand::class,
            ]);
        }
    }
}
