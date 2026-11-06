<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Console;

use App\Domains\Identity\Contracts\IdentityApi;
use App\Models\Tenant;
use App\Platform\Queueing\Authorization\QueueRolePermissions;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;

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

    public function handle(IdentityApi $identityApi): int
    {
        $tenantIds = $this->option('tenant') !== null
            ? [(string) $this->option('tenant')]
            : Tenant::query()->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $dryRun = (bool) $this->option('dry-run');
        $attachedTotal = 0;
        $removedTotal = 0;

        foreach ($tenantIds as $tenantId) {
            TenantContextScope::run($tenantId, null, function () use (
                $tenantId,
                $dryRun,
                $identityApi,
                &$attachedTotal,
                &$removedTotal,
            ): void {
                $sync = $identityApi->synchronizeRolePermissions(
                    $tenantId,
                    QueueRolePermissions::defaults(),
                    $dryRun,
                    ['queue.definition.manage'],
                );

                foreach ($sync['missingRoles'] as $roleKey) {
                    $this->warn("Tenant {$tenantId}: role '{$roleKey}' not found, skipped.");
                }

                foreach ($sync['changes'] as $change) {
                    if ($change['operation'] === 'attached') {
                        $attachedTotal++;
                        $this->line(
                            "Tenant {$tenantId}: {$change['roleKey']} <= {$change['permissionKey']}"
                                .($dryRun ? ' (dry run)' : ''),
                        );

                        continue;
                    }

                    $removedTotal++;
                    $this->line(
                        "Tenant {$tenantId}: {$change['roleKey']} -/ {$change['permissionKey']}"
                            .($dryRun ? ' (dry run)' : ''),
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
