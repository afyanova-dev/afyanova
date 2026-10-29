<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Authorization\ProtectedRoleRegistry;
use App\Domains\Identity\Domain\Model\Permission;
use App\Domains\Identity\Domain\Model\Role;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** PHASE 3 ops entry point for roles (docs/00-foundation/03-identity-rbac.md §8, §35). */
final class ManageRoleCommand extends Command
{
    protected $signature = 'afyanova:identity:role
        {action : create|grant|status}
        {--tenant= : tenant uuid}
        {--key= : role key}
        {--name= : role name}
        {--parent= : parent role key (permission inheritance only)}
        {--scope=FACILITY : ORG|FACILITY|DEPARTMENT|UNIT}
        {--permission=* : permission key(s) for grant}
        {--to=ACTIVE : DRAFT|ACTIVE|SUSPENDED|RETIRED for status}';

    protected $description = 'Create a role, grant it permissions, or change its lifecycle status.';

    public function handle(AuditLogger $audit): int
    {
        $tenantId = (string) $this->option('tenant');
        $key = (string) $this->option('key');
        if ($tenantId === '' || $key === '') {
            $this->error('--tenant and --key are required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $key, $audit): int {
            return match ($this->argument('action')) {
                'create' => $this->create($tenantId, $key, $audit),
                'grant' => $this->grant($tenantId, $key, $audit),
                'status' => $this->status($tenantId, $key, $audit),
                default => tap(self::FAILURE, fn () => $this->error('action must be create|grant|status')),
            };
        });
    }

    private function create(string $tenantId, string $key, AuditLogger $audit): int
    {
        if (ProtectedRoleRegistry::contains($key)) {
            $this->error("Role key {$key} is reserved for a protected built-in bundle.");

            return self::FAILURE;
        }

        $parentId = null;
        if ($this->option('parent')) {
            $parent = Role::query()->where('key', $this->option('parent'))->first();
            if ($parent === null) {
                $this->error("Parent role {$this->option('parent')} not found.");

                return self::FAILURE;
            }
            if ($this->hasProtectedAncestor($parent)) {
                $this->error('Custom roles cannot inherit permissions from a protected role.');

                return self::FAILURE;
            }
            $parentId = (string) $parent->getKey();
        }

        $role = DB::transaction(function () use ($tenantId, $key, $parentId, $audit): ?Role {
            $role = Role::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key],
                [
                    'name' => (string) ($this->option('name') ?: $key),
                    'status' => 'ACTIVE',
                    'parent_role_id' => $parentId,
                    'scope_type' => (string) $this->option('scope'),
                ],
            );

            if ($role->is_protected || $this->hasProtectedAncestor($role)) {
                return null;
            }

            if ($role->wasRecentlyCreated) {
                $audit->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.role.created',
                    metadata: [
                        'role' => $key,
                        'status' => $role->status->value,
                        'scope' => $role->scope_type->value,
                    ],
                    targetType: 'role',
                    targetId: (string) $role->getKey(),
                );
            }

            return $role;
        });

        if ($role === null) {
            $this->error("Role {$key} is protected or inherits from a protected role and cannot be changed through this command.");

            return self::FAILURE;
        }

        $this->info("Role {$key} = {$role->id}");

        return self::SUCCESS;
    }

    private function grant(string $tenantId, string $key, AuditLogger $audit): int
    {
        $role = Role::query()->where('key', $key)->first();
        if ($role === null) {
            $this->error("Role {$key} not found.");

            return self::FAILURE;
        }

        if ($role->is_protected) {
            $this->error("Role {$key} is protected and cannot be changed through this command.");

            return self::FAILURE;
        }
        if ($this->hasProtectedAncestor($role)) {
            $this->error("Role {$key} inherits from a protected bundle and cannot change lifecycle through this command.");

            return self::FAILURE;
        }

        /** @var list<string> $keys */
        $keys = array_values(array_unique((array) $this->option('permission')));
        foreach ($keys as $permKey) {
            if (! PermissionRegistry::has($permKey)) {
                $this->error("Permission '{$permKey}' is not registered.");

                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($tenantId, $role, $key, $keys, $audit): void {
            $granted = [];
            foreach ($keys as $permKey) {
                $perm = Permission::query()->firstOrCreate(['key' => $permKey]);
                if ($role->permissions()->whereKey($perm->getKey())->exists()) {
                    continue;
                }

                $role->permissions()->syncWithoutDetaching([
                    $perm->getKey() => ['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId],
                ]);
                $granted[] = $permKey;
            }

            if ($granted !== []) {
                $audit->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.role.permissions_granted',
                    metadata: [
                        'role' => $key,
                        'permissions' => array_slice($granted, 0, 100),
                        'permissions_count' => count($granted),
                        'permissions_truncated' => count($granted) > 100,
                    ],
                    targetType: 'role',
                    targetId: (string) $role->getKey(),
                );
            }
        });

        $this->info('Granted '.count($keys)." permission(s) to {$key}.");

        return self::SUCCESS;
    }

    private function status(string $tenantId, string $key, AuditLogger $audit): int
    {
        $role = Role::query()->where('key', $key)->first();
        if ($role === null) {
            $this->error("Role {$key} not found.");

            return self::FAILURE;
        }
        if ($role->is_protected) {
            $this->error("Role {$key} is protected and cannot be changed through this command.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($tenantId, $role, $key, $audit): void {
            $role->update(['status' => (string) $this->option('to')]);

            if ($role->wasChanged('status')) {
                $audit->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.role.status_changed',
                    metadata: ['role' => $key, 'status' => $role->status->value],
                    targetType: 'role',
                    targetId: (string) $role->getKey(),
                );
            }
        });
        $this->info("Role {$key} → {$this->option('to')}");

        return self::SUCCESS;
    }

    private function hasProtectedAncestor(Role $role): bool
    {
        $seen = [];
        $ancestor = $role;

        while (true) {
            $id = (string) $ancestor->getKey();
            if (isset($seen[$id]) || $ancestor->is_protected) {
                return true;
            }
            $seen[$id] = true;

            if ($ancestor->parent_role_id === null) {
                return false;
            }

            $ancestor = Role::query()->find($ancestor->parent_role_id);
            if ($ancestor === null) {
                return true;
            }
        }
    }
}
