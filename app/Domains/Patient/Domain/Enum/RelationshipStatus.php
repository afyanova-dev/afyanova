<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lifecycle of a patient relationship record (ADR-126 D-8). A relationship is
 * DEACTIVATED, not hard-deleted — the row and its audit trail are preserved
 * where historical integrity matters.
 */
#[TypeScript]
enum RelationshipStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
