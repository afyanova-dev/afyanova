<?php

declare(strict_types=1);

namespace App\Domains\Identity\Console;

use App\Domains\Identity\Application\LinkedStaffGrantEligibility;
use App\Domains\Identity\Domain\Model\AccessAssignment;
use App\Domains\Identity\Domain\Model\Role;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** PHASE 3 ops entry point for access assignments (docs/00-foundation/03-identity-rbac.md §6, §37–§38). */
final class ManageAssignmentCommand extends Command
{
    protected $signature = 'afyanova:identity:assign
        {action : grant|revoke}
        {--tenant= : tenant uuid}
        {--user= : user uuid}
        {--role= : role key}
        {--organization= : organization uuid scope}
        {--facility= : facility uuid scope}
        {--department= : department uuid scope}
        {--unit= : unit uuid scope}
        {--from= : effective_from (Y-m-d) — temporary assignment}
        {--to= : effective_to (Y-m-d)}
        {--assignment= : assignment uuid for revoke}';

    protected $description = 'Grant or revoke a user access assignment (role + scope).';

    public function handle(AuditLogger $audit, LinkedStaffGrantEligibility $grantEligibility): int
    {
        $tenantId = (string) $this->option('tenant');
        if ($tenantId === '') {
            $this->error('--tenant is required.');

            return self::FAILURE;
        }

        return TenantContextScope::run($tenantId, null, function () use ($tenantId, $audit, $grantEligibility): int {
            if ($this->argument('action') === 'revoke') {
                $id = (string) $this->option('assignment');
                $assignment = DB::transaction(function () use ($tenantId, $id, $audit): ?AccessAssignment {
                    $assignment = AccessAssignment::query()->find($id);
                    if ($assignment === null) {
                        return null;
                    }

                    if ($assignment->status->value !== 'REVOKED') {
                        $assignment->update(['status' => 'REVOKED']);
                        $audit->recordFor(
                            tenantId: $tenantId,
                            action: 'identity.assignment.revoke',
                            facilityId: $assignment->facility_id,
                            metadata: [
                                'assignment' => (string) $assignment->getKey(),
                                'role' => (string) Role::query()->whereKey($assignment->role_id)->value('key'),
                            ],
                            targetType: 'assignment',
                            targetId: (string) $assignment->getKey(),
                        );
                    }

                    return $assignment;
                });
                $ok = $assignment !== null;
                $this->info($ok ? "Assignment {$id} revoked." : "Assignment {$id} not found.");

                return $ok ? self::SUCCESS : self::FAILURE;
            }

            $role = Role::query()->where('key', $this->option('role'))->first();
            if ($role === null) {
                $this->error("Role {$this->option('role')} not found.");

                return self::FAILURE;
            }
            if ($this->isAdministrativeRole($role)) {
                $this->error('Administrative role promotions require the separate two-person approval workflow.');

                return self::FAILURE;
            }

            $a = DB::transaction(function () use ($tenantId, $role, $audit, $grantEligibility): AccessAssignment {
                $grantEligibility->lockEligibleTarget(
                    $tenantId,
                    (string) $this->option('user'),
                );
                $assignment = AccessAssignment::query()->create([
                    'tenant_id' => $tenantId,
                    'user_id' => (string) $this->option('user'),
                    'role_id' => $role->getKey(),
                    'organization_id' => $this->option('organization') ?: null,
                    'facility_id' => $this->option('facility') ?: null,
                    'department_id' => $this->option('department') ?: null,
                    'unit_id' => $this->option('unit') ?: null,
                    'status' => 'ACTIVE',
                    'effective_from' => $this->option('from') ?: null,
                    'effective_to' => $this->option('to') ?: null,
                ]);

                $audit->recordFor(
                    tenantId: $tenantId,
                    action: 'identity.assignment.grant',
                    facilityId: $assignment->facility_id,
                    metadata: [
                        'role' => (string) $role->key,
                        'assignment' => (string) $assignment->getKey(),
                    ],
                    targetType: 'assignment',
                    targetId: (string) $assignment->getKey(),
                );

                return $assignment;
            });

            $this->info("Assignment {$a->id} granted.");

            return self::SUCCESS;
        });
    }

    private function isAdministrativeRole(Role $role): bool
    {
        $seen = [];
        $current = $role;

        while (true) {
            $roleId = (string) $current->getKey();
            if (isset($seen[$roleId])) {
                return true;
            }
            $seen[$roleId] = true;

            if (in_array($current->key, ['tenant_admin', 'facility_admin'], true)
                || DB::table('idn_permission_role as pr')
                    ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
                    ->where('pr.role_id', $roleId)
                    ->where('p.key', 'admin.workspace.access')
                    ->exists()) {
                return true;
            }

            if ($current->parent_role_id === null) {
                return false;
            }

            $parent = Role::query()->find($current->parent_role_id);
            if ($parent === null) {
                return true;
            }
            $current = $parent;
        }
    }
}
