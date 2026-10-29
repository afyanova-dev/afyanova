<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lifecycle of a patient address (ADR-126 D-8; 10-patient-domain.md §34). An
 * address is DEACTIVATED, not hard-deleted — a move keeps the prior row so
 * historical records are not erased.
 */
#[TypeScript]
enum AddressStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
}
