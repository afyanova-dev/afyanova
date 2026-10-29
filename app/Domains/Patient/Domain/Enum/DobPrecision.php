<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Distinguishes a confirmed date of birth from an estimated or unknown one
 * (10-patient-domain.md §28, Invariant 10: "Estimated DOB is distinguishable
 * from confirmed DOB").
 */
#[TypeScript]
enum DobPrecision: string
{
    case Known = 'KNOWN';
    case Estimated = 'ESTIMATED';
    case Unknown = 'UNKNOWN';
}
