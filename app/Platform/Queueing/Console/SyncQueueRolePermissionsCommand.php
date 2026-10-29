<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Console;

use App\Domains\Identity\Domain\Model\Permission;
use App\Domains\Identity\Domain\Model\Role;
use App\Models\Tenant;
use App\Platform\Queueing\Authorization\QueueRolePermissions;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Backfills the queue permission keys from {@see QueueRolePermissions} onto
 * the demo roles of existing databases (fresh seeds get them from
 * `DatabaseSeeder`). Idempotent: missing keys are attached and unauthorized
 * queue-management grants are removed from roles that should not hold them.
 */
final class SyncQueueRolePermissionsCommand extends Command
{
    protected $signature = 'queue:sync-role-permissions
        {--tenant= : Tenant UUID; every tenant when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Synchronize queue permission keys for configured roles.';

    public function handle(): int
    {
        $tenantIds = $this->option('tenant') !== null
            ? [(string) $this->option('tenant')]
            : Tenant::query()->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $dryRun = (bool) $this->option('dry-run');
        $attachedTotal = 0;
        $removedTotal = 0;

        foreach ($tenantIds as $tenantId) {
            TenantContextScope::run($tenantId, null, function () use ($tenantId, $dryRun, &$attachedTotal, &$removedTotal): void {
                foreach (QueueRolePermissions::defaults() as $roleKey => $keys) {
                    $role = Role::query()->where('key', $roleKey)->first();

                    if ($role === null) {
                        $this->warn("Tenant {$tenantId}: role '{$roleKey}' not found, skipped.");

                        continue;
                    }

                    foreach ($keys as $key) {
                        if (! PermissionRegistry::has($key)) {
                            continue;
                        }

                        $perm = Permission::query()->firstOrCreate(
                            ['key' => $key],
                            ['layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION']
                        );

                        $already = $role->permissions()->where('idn_permissions.key', $key)->exists();

                        if ($already) {
                            continue;
                        }

                        if (! $dryRun) {
                            $role->permissions()->syncWithoutDetaching([
                                $perm->id => ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId],
                            ]);
                        }

                        $attachedTotal++;
                        $this->line("Tenant {$tenantId}: {$roleKey} <= {$key}".($dryRun ? ' (dry run)' : ''));
                    }

                    if (in_array('queue.definition.manage', $keys, true)) {
                        continue;
                    }

                    $managePermission = Permission::query()
                        ->where('key', 'queue.definition.manage')
                        ->first();
                    if (
                        $managePermission === null
                        || ! $role->permissions()->whereKey($managePermission->id)->exists()
                    ) {
                        continue;
                    }

                    if (! $dryRun) {
                        $role->permissions()->detach($managePermission->id);
                    }

                    $removedTotal++;
                    $this->line(
                        "Tenant {$tenantId}: {$roleKey} -/ queue.definition.manage".($dryRun ? ' (dry run)' : ''),
                    );
                }
            });
        }

        $this->info($dryRun
            ? "Dry run: {$attachedTotal} permission grant(s) would be attached; {$removedTotal} unauthorized queue-management grant(s) would be removed."
            : "Attached {$attachedTotal} missing permission grant(s); removed {$removedTotal} unauthorized queue-management grant(s).");

        return self::SUCCESS;
    }
}
