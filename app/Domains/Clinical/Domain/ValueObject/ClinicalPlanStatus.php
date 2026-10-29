<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use App\Domains\Clinical\Domain\Model\ClinicalPlan;
use InvalidArgumentException;

/**
 * Closed lifecycle status set for {@see ClinicalPlan}
 * (ADR-143 D-E): ACTIVE | COMPLETED | DISCONTINUED.
 *
 * Backend-only string-constant holder (NOT a PHP enum, NOT #[TypeScript]).
 */
final class ClinicalPlanStatus
{
    public const ACTIVE = 'ACTIVE';

    public const COMPLETED = 'COMPLETED';

    public const DISCONTINUED = 'DISCONTINUED';

    public const ALL = [
        self::ACTIVE,
        self::COMPLETED,
        self::DISCONTINUED,
    ];

    public static function assert(string $value): string
    {
        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ClinicalPlanStatus [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }
}
