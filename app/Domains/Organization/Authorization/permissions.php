<?php

declare(strict_types=1);

/**
 * Organization permission keys (docs/00-foundation/03-identity-rbac.md §31–§33,
 * §66). L1 `.access` + L2 `{resource}.{action}`. Aggregated into
 * PermissionRegistry by OrganizationServiceProvider::boot().
 *
 * @return list<string>
 */
return [
    'organization.access',
    'organization.facilities.read',
    'organization.facilities.manage',
    'organization.structure.read',
    'organization.structure.manage',
    'organization.capabilities.read',
    'organization.capabilities.manage',
];
