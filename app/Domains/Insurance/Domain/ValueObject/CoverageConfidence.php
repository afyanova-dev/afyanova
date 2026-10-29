<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

/**
 * How firm a coverage decision is (21-insurance-and-payer-domain.md §167;
 * ADR-200 D-C): CONFIRMED | PROVISIONAL | UNKNOWN.
 *
 * Ins.3: CONFIRMED for a COVERED / NOT_COVERED decision from a configured rule
 * (or a firm "not eligible"); PROVISIONAL when the rule is
 * REQUIRES_AUTHORIZATION (the payer amount is contingent on Ins.5 approval +
 * Ins.8 adjudication); UNKNOWN when no rule is configured. Backend-only
 * string-constant holder.
 */
final class CoverageConfidence
{
    public const CONFIRMED = 'CONFIRMED';

    public const PROVISIONAL = 'PROVISIONAL';

    public const UNKNOWN = 'UNKNOWN';

    /** @var list<string> */
    public const ALL = [self::CONFIRMED, self::PROVISIONAL, self::UNKNOWN];
}
