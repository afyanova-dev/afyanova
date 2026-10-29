<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Clinical note lifecycle state (12-clinical-domain.md §12; ADR-134). Exactly
 * three cases for PHASE 6 (C.1):
 *
 *   - `DRAFT`      — the note has been created but not yet attested;
 *   - `SIGNED`     — the author has attested the note; the body is immutable
 *                    thereafter and corrections are appended as amendments;
 *   - `CANCELLED`  — a `DRAFT` created in error, discarded before signing.
 *
 * `AMENDED` is NOT a case — it is DERIVED (`status === SIGNED` AND at least one
 * `clin_note_amendments` row, surfaced as `ClinicalNoteSummary::$amendmentCount`).
 * This mirrors the ADR-121 discipline that `REFERRED` is a disposition, not a
 * status. `IN_REVIEW` / co-signature / `VOIDED` are deferred (ADR-134).
 */
#[TypeScript]
enum ClinicalNoteStatus: string
{
    case Draft = 'DRAFT';
    case Signed = 'SIGNED';
    case Cancelled = 'CANCELLED';
}
