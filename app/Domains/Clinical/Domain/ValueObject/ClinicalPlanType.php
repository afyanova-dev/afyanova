<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use InvalidArgumentException;

/**
 * Coded plan type for {@see ClinicalPlan}
 * (12-clinical-domain.md §34; ADR-143 D-D): the six §34 kinds plus `GENERAL`
 * as the escape —
 * MEDICATION | INVESTIGATION | PROCEDURE | FOLLOW_UP | REFERRAL | OBSERVATION | GENERAL.
 *
 * Behaviour-neutral string-constant holder (NOT a PHP enum, NOT #[TypeScript]).
 */
final class ClinicalPlanType
{
    public const MEDICATION = 'MEDICATION';

    public const INVESTIGATION = 'INVESTIGATION';

    public const PROCEDURE = 'PROCEDURE';

    public const FOLLOW_UP = 'FOLLOW_UP';

    public const REFERRAL = 'REFERRAL';

    public const OBSERVATION = 'OBSERVATION';

    public const GENERAL = 'GENERAL';

    public const ALL = [
        self::MEDICATION,
        self::INVESTIGATION,
        self::PROCEDURE,
        self::FOLLOW_UP,
        self::REFERRAL,
        self::OBSERVATION,
        self::GENERAL,
    ];

    public static function assert(string $value): string
    {
        $normalized = strtoupper(trim($value));

        if (! in_array($normalized, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ClinicalPlanType [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $normalized;
    }

    public static function isValid(string $value): bool
    {
        return in_array(strtoupper(trim($value)), self::ALL, true);
    }
}
