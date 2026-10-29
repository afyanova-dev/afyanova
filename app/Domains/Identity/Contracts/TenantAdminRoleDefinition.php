<?php

declare(strict_types=1);

namespace App\Domains\Identity\Contracts;

final class TenantAdminRoleDefinition
{
    public const KEY = 'tenant_admin';

    public const PERMISSIONS = [
        'admin.workspace.access',
        'audit.events.read',
        'identity.access',
        'identity.users.read',
        'identity.users.manage',
        'identity.roles.read',
        'identity.roles.manage',
        'identity.assignments.read',
        'identity.assignments.manage',
        'identity.staff.read',
        'identity.staff.manage',
        'organization.access',
        'organization.facilities.read',
        'organization.facilities.manage',
        'organization.structure.read',
        'organization.structure.manage',
        'organization.capabilities.read',
        'organization.capabilities.manage',
        'inventory.access',
        'inventory.items.manage',
        'inventory.location_types.manage',
        // Shape B — a Tenant Admin sets the single-actor approval limit for ANY
        // facility (the page scopes each facility through the actor's real
        // permissions, so this key is what tenant-wide administration uses).
        'procurement.approval_limits.manage',
        // Proc.5 — read-only procurement oversight. `procurement.access` is the
        // coarse READ key of the module (`permissions.php`: "read the module
        // (suppliers + requisitions + POs)"), so the Tenant Admin enters the
        // workspace and observes every facility it reaches (ScopeChecker's
        // NULL-facility wildcard). Every WRITE stays on the per-action keys
        // (`.suppliers.manage` / `.requisitions.*` / `.orders.*`), which this
        // bundle deliberately does not carry — the Tenant Admin never becomes
        // a procurement actor (§24 / §228 different-actor guards untouched).
        'procurement.access',
    ];
}
