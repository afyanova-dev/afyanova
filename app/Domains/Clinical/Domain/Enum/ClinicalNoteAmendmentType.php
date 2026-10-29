<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The kind of correction an amendment row records against a SIGNED clinical
 * note (12-clinical-domain.md §14; ADR-134). A closed coded set for C.1:
 *
 *   - `AMENDMENT`  — a change to previously recorded content;
 *   - `ADDENDUM`   — new information added after signing, nothing retracted;
 *   - `CORRECTION` — a factual error being fixed.
 *
 * Behaviour-neutral: the lifecycle (`SIGNED → SIGNED`) and authorization
 * (`clinical.notes.amend`) are identical for every value. Additive-safe — a
 * later ADR may add a case.
 */
#[TypeScript]
enum ClinicalNoteAmendmentType: string
{
    case Amendment = 'AMENDMENT';
    case Addendum = 'ADDENDUM';
    case Correction = 'CORRECTION';
}
