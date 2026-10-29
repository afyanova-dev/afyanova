<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

/**
 * A suggested vocabulary for a procedure's clinical outcome
 * (13-clinical-procedures-domain.md §38, §85; ADR-146 D-I).
 *
 * `outcome` on `ClinicalProcedure` is an OPEN coded string (the "no terminology
 * engine" discipline — ADR-139) — these constants are a documented suggestion
 * for callers, NOT an enforced set (a `ProcedureDefinition` may specify its own
 * vocabulary; 13 §38 "the exact clinical outcome vocabulary should be
 * procedure-specific"). The write path shape-validates only (non-blank, bounded
 * length) — there is no `assert()` against {@see ALL}. Backend-only, NOT
 * `#[TypeScript]` (no PHASE-6 consumer).
 */
final class ProcedureOutcome
{
    public const SUCCESSFUL = 'SUCCESSFUL';

    public const PARTIALLY_SUCCESSFUL = 'PARTIALLY_SUCCESSFUL';

    public const UNSUCCESSFUL = 'UNSUCCESSFUL';

    public const COMPLICATION = 'COMPLICATION';

    /** @var list<string> */
    public const ALL = [
        self::SUCCESSFUL,
        self::PARTIALLY_SUCCESSFUL,
        self::UNSUCCESSFUL,
        self::COMPLICATION,
    ];
}
