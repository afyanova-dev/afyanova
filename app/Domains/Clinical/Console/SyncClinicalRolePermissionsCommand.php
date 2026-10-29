<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Console;

use App\Domains\Clinical\Authorization\ClinicalRolePermissions;
use App\Domains\Identity\Domain\Model\Permission;
use App\Domains\Identity\Domain\Model\Role;
use App\Models\Tenant;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class SyncClinicalRolePermissionsCommand extends Command
{
    protected $signature = 'clinical:sync-role-permissions
        {--tenant= : Tenant UUID; every tenant when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Synchronize clinical permission keys for configured demo roles.';

    public function handle(): int
    {
        $tenantIds = $this->option('tenant') !== null
            ? [(string) $this->option('tenant')]
            : Tenant::query()->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $dryRun = (bool) $this->option('dry-run');
        $attachedTotal = 0;

        foreach ($tenantIds as $tenantId) {
            TenantContextScope::run($tenantId, null, function () use ($tenantId, $dryRun, &$attachedTotal): void {
                foreach (ClinicalRolePermissions::defaults() as $roleKey => $keys) {
                    $role = Role::query()->where('key', $roleKey)->first();

                    if ($role === null) {
                        $this->warn("Tenant {$tenantId}: role '{$roleKey}' not found, skipped.");

                        continue;
                    }

                    foreach ($keys as $key) {
                        if (! PermissionRegistry::has($key)) {
                            continue;
                        }

                        $permission = Permission::query()->firstOrCreate(
                            ['key' => $key],
                            ['layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION'],
                        );
                        $alreadyAssigned = $role->permissions()
                            ->where('idn_permissions.key', $key)
                            ->exists();

                        if ($alreadyAssigned) {
                            continue;
                        }

                        if (! $dryRun) {
                            $role->permissions()->syncWithoutDetaching([
                                $permission->id => [
                                    'id' => (string) Str::uuid7(),
                                    'tenant_id' => $tenantId,
                                ],
                            ]);
                        }

                        $attachedTotal++;
                        $this->line("Tenant {$tenantId}: {$roleKey} <= {$key}".($dryRun ? ' (dry run)' : ''));
                    }
                }
            });
        }

        $this->info($dryRun
            ? "Dry run: {$attachedTotal} clinical permission grant(s) would be attached."
            : "Attached {$attachedTotal} missing clinical permission grant(s).");

        return self::SUCCESS;
    }
}
