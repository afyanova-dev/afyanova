<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * State of a `pat_patient_identity_history` row (ADR-128 M-4/M-5).
 *
 *   ACTIVE    the merge is in force — the mapping resolves the non-surviving
 *             identity to the survivor
 *   REVERSED  RequestUnmerge has undone the identity-layer merge; the row is
 *             kept as immutable historical evidence, never deleted
 */
#[TypeScript]
enum MergeHistoryStatus: string
{
    case Active = 'ACTIVE';
    case Reversed = 'REVERSED';
}
