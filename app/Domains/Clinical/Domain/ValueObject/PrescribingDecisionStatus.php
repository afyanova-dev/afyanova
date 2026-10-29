<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use App\Domains\Clinical\Domain\Model\PrescribingDecision;
use InvalidArgumentException;

/**
 * Lifecycle status set for {@see PrescribingDecision}
 * (ADR-143 D-J): ACTIVE | SUPERSEDED | DISCONTINUED.
 *
 * Backend-only string-constant holder (NOT a PHP enum, NOT #[TypeScript]).
 */
final class PrescribingDecisionStatus
{
    public const ACTIVE = 'ACTIVE';

    public const SUPERSEDED = 'SUPERSEDED';

    public const DISCONTINUED = 'DISCONTINUED';

    public const ALL = [
        self::ACTIVE,
        self::SUPERSEDED,
        self::DISCONTINUED,
    ];

    public static function assert(string $value): string
    {
        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid PrescribingDecisionStatus [%s]. Permitted: %s.',
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
