<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Console;

use App\Domains\Clinical\Authorization\ClinicalRolePermissions;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Models\Tenant;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;

final class SyncClinicalRolePermissionsCommand extends Command
{
    protected $signature = 'clinical:sync-role-permissions
        {--tenant= : Tenant UUID; every tenant when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Synchronize clinical permission keys for configured demo roles.';

    public function handle(IdentityApi $identityApi): int
    {
        $tenantIds = $this->option('tenant') !== null
            ? [(string) $this->option('tenant')]
            : Tenant::query()->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $dryRun = (bool) $this->option('dry-run');
        $attachedTotal = 0;

        foreach ($tenantIds as $tenantId) {
            TenantContextScope::run($tenantId, null, function () use (
                $tenantId,
                $dryRun,
                $identityApi,
                &$attachedTotal,
            ): void {
                $sync = $identityApi->synchronizeRolePermissions(
                    $tenantId,
                    ClinicalRolePermissions::defaults(),
                    $dryRun,
                );

                foreach ($sync['missingRoles'] as $roleKey) {
                    $this->warn("Tenant {$tenantId}: role '{$roleKey}' not found, skipped.");
                }

                foreach ($sync['changes'] as $change) {
                    $attachedTotal++;
                    $this->line(
                        "Tenant {$tenantId}: {$change['roleKey']} <= {$change['permissionKey']}"
                            .($dryRun ? ' (dry run)' : ''),
                    );
                }
            });
        }

        $this->info($dryRun
            ? "Dry run: {$attachedTotal} clinical permission grant(s) would be attached."
            : "Attached {$attachedTotal} missing clinical permission grant(s).");

        return self::SUCCESS;
    }
}
