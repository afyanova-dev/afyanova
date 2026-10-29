<?php

declare(strict_types=1);

namespace App\Domains\Identity\Domain\Enum;

/** docs/00-foundation/03-identity-rbac.md §66. */
enum PermissionLayer: string
{
    case Access = 'ACCESS';  // L1 — {domain}.access
    case Action = 'ACTION';  // L2 — {domain}.{resource}.{action}
}
