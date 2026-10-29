<?php

declare(strict_types=1);

/**
 * Identity & RBAC permission keys (docs/00-foundation/03-identity-rbac.md
 * §31–§33, §66). L1 `.access` + L2 `{resource}.{action}`.
 *
 * @return list<string>
 */
return [
    'identity.access',
    'identity.users.read',
    'identity.users.manage',
    'identity.roles.read',
    'identity.roles.manage',
    'identity.assignments.read',
    'identity.assignments.manage',
    'identity.staff.read',
    'identity.staff.manage',
];
