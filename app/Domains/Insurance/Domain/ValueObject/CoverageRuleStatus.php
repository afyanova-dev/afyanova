<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle state of a plan coverage rule row (ADR-200 D-A): the `bill_tariffs`
 * `TariffStatus` shape — CURRENT | SUPERSEDED | ENDED.
 *
 * CURRENT = the open row for its (plan, category) scope. SUPERSEDED = closed by
 * a later `SetPlanCoverageRule`. ENDED = closed by `EndPlanCoverageRule`. Rows
 * are never deleted (§162 / §225 / §331). Backend-only string-constant holder.
 */
final class CoverageRuleStatus
{
    public const CURRENT = 'CURRENT';

    public const SUPERSEDED = 'SUPERSEDED';

    public const ENDED = 'ENDED';

    /** @var list<string> */
    public const ALL = [self::CURRENT, self::SUPERSEDED, self::ENDED];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid coverage-rule status [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }
}
