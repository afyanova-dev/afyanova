<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Contracts\BillingAdminRoleDefinition;
use App\Domains\Identity\Contracts\CashierRoleDefinition;
use App\Domains\Identity\Contracts\ClinicianRoleDefinition;
use App\Domains\Identity\Contracts\FacilityAdminRoleDefinition;
use App\Domains\Identity\Contracts\FacilityStaffManagerRoleDefinition;
use App\Domains\Identity\Contracts\LaboratoryTechnicianRoleDefinition;
use App\Domains\Identity\Contracts\NurseRoleDefinition;
use App\Domains\Identity\Contracts\PharmacistRoleDefinition;
use App\Domains\Identity\Contracts\ReceptionistRoleDefinition;
use App\Domains\Identity\Contracts\TenantAdminRoleDefinition;
use App\Domains\Identity\Domain\Model\Permission;
use App\Domains\Identity\Domain\Model\Role;
use App\Models\Tenant;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Backfills the permission keys carried by the Identity-owned protected role
 * bundles onto the matching role rows of existing databases (a fresh seed gets
 * them from `DatabaseSeeder`) — the `queue:sync-role-permissions` /
 * `clinical:sync-role-permissions` precedent. A role-definition change (for
 * example Phase 7 adding `procurement.access` to
 * {@see FacilityAdminRoleDefinition}) cannot reach an already-provisioned
 * tenant, and a real tenant cannot be re-seeded.
 *
 * **Additive only.** A missing canonical key is attached; nothing is ever
 * detached, so the command can never widen or narrow a role beyond its
 * definition. Idempotent — a second run attaches nothing. Each role that
 * changes is audited (`identity.role.definition_reconciled`).
 */
final class SyncRolePermissionsCommand extends Command
{
    protected $signature = 'identity:sync-role-permissions
        {--tenant= : Tenant UUID; every tenant when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Attach missing canonically-defined permissions to the Identity protected roles.';

    public function handle(AuditLogger $audit): int
    {
        $tenantIds = $this->option('tenant') !== null
            ? [(string) $this->option('tenant')]
            : Tenant::query()->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        $dryRun = (bool) $this->option('dry-run');
        $attachedTotal = 0;

        foreach ($tenantIds as $tenantId) {
            TenantContextScope::run($tenantId, null, function () use ($tenantId, $dryRun, $audit, &$attachedTotal): void {
                foreach (self::definitions() as $roleKey => $keys) {
                    $role = Role::query()->where('key', $roleKey)->first();

                    if ($role === null) {
                        $this->warn("Tenant {$tenantId}: role '{$roleKey}' not found, skipped.");

                        continue;
                    }

                    $attached = [];
                    foreach ($keys as $key) {
                        if (! PermissionRegistry::has($key)) {
                            continue;
                        }

                        if ($role->permissions()->where('idn_permissions.key', $key)->exists()) {
                            continue;
                        }

                        if (! $dryRun) {
                            $permission = Permission::query()->firstOrCreate(
                                ['key' => $key],
                                ['layer' => str_ends_with($key, '.access') ? 'ACCESS' : 'ACTION'],
                            );

                            $role->permissions()->syncWithoutDetaching([
                                $permission->id => ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId],
                            ]);
                        }

                        $attached[] = $key;
                        $this->line("Tenant {$tenantId}: {$roleKey} <= {$key}".($dryRun ? ' (dry run)' : ''));
                    }

                    if ($attached === []) {
                        continue;
                    }

                    $attachedTotal += count($attached);

                    if (! $dryRun) {
                        $audit->recordFor(
                            tenantId: $tenantId,
                            action: 'identity.role.definition_reconciled',
                            metadata: [
                                'role' => $roleKey,
                                'permissions_added' => $attached,
                            ],
                            targetType: 'role',
                            targetId: (string) $role->getKey(),
                        );
                    }
                }
            });
        }

        $this->info($dryRun
            ? "Dry run: {$attachedTotal} permission grant(s) would be attached."
            : "Attached {$attachedTotal} missing permission grant(s).");

        return self::SUCCESS;
    }

    /**
     * The Identity-owned protected role bundles and their canonical permission
     * keys. Keys are unique, so the map has one entry per role.
     *
     * @return array<string, list<string>>
     */
    private static function definitions(): array
    {
        return [
            FacilityAdminRoleDefinition::KEY => FacilityAdminRoleDefinition::PERMISSIONS,
            TenantAdminRoleDefinition::KEY => TenantAdminRoleDefinition::PERMISSIONS,
            FacilityStaffManagerRoleDefinition::KEY => FacilityStaffManagerRoleDefinition::PERMISSIONS,
            ReceptionistRoleDefinition::KEY => ReceptionistRoleDefinition::PERMISSIONS,
            CashierRoleDefinition::KEY => CashierRoleDefinition::PERMISSIONS,
            BillingAdminRoleDefinition::KEY => BillingAdminRoleDefinition::PERMISSIONS,
            PharmacistRoleDefinition::KEY => PharmacistRoleDefinition::PERMISSIONS,
            NurseRoleDefinition::KEY => NurseRoleDefinition::PERMISSIONS,
            ClinicianRoleDefinition::KEY => ClinicianRoleDefinition::PERMISSIONS,
            LaboratoryTechnicianRoleDefinition::KEY => LaboratoryTechnicianRoleDefinition::PERMISSIONS,
        ];
    }
}
