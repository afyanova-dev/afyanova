<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lifecycle of a secondary patient contact point (ADR-126 D-8). A contact is
 * DEACTIVATED, not hard-deleted — the row and its audit trail are preserved.
 */
#[TypeScript]
enum ContactStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
