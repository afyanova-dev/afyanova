<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The fixed three-outcome contract for pre-commit duplicate detection
 * (ADR-117; 10-patient-domain.md §17-20, Invariant 5). The scoring that
 * arrives at one of these is tenant-configurable (§160) — only the outcomes
 * are canonical.
 *
 *   Block  — high-confidence identity collision. RegisterPatient rejected (409).
 *   Review — a meaningful potential match. No patient is created; the caller
 *            gets candidate id(s) and either reuses one or re-submits with a
 *            permissioned override.
 *   Allow  — low-confidence similarity or none. RegisterPatient proceeds.
 */
#[TypeScript]
enum DuplicateOutcome: string
{
    case Block = 'BLOCK';
    case Review = 'REVIEW';
    case Allow = 'ALLOW';
}
