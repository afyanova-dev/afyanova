<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use App\Domains\Insurance\Contracts\Dto\CoverageDecision;

/**
 * The DERIVED coverage status a {@see CoverageDecision}
 * reports (21-insurance-and-payer-domain.md §33; ADR-200 D-C): the §33 set —
 * COVERED | PARTIALLY_COVERED | NOT_COVERED | REQUIRES_AUTHORIZATION | UNKNOWN.
 *
 * Distinct from the stored {@see CoverageRuleOutcome}: `PARTIALLY_COVERED` is
 * derived (COVERED with a percent < 100, a co-pay, or a fixed benefit);
 * `UNKNOWN` is returned when no rule is configured (the §106 "unpriced as
 * data" precedent). Backend-only string-constant holder.
 */
final class CoverageDecisionStatus
{
    public const COVERED = 'COVERED';

    public const PARTIALLY_COVERED = 'PARTIALLY_COVERED';

    public const NOT_COVERED = 'NOT_COVERED';

    public const REQUIRES_AUTHORIZATION = 'REQUIRES_AUTHORIZATION';

    public const UNKNOWN = 'UNKNOWN';

    /** @var list<string> */
    public const ALL = [
        self::COVERED,
        self::PARTIALLY_COVERED,
        self::NOT_COVERED,
        self::REQUIRES_AUTHORIZATION,
        self::UNKNOWN,
    ];
}
