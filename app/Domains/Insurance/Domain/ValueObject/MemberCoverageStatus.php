<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle state of a member coverage record
 * (21-insurance-and-payer-domain.md §14, §319; ADR-199 D-A): ACTIVE |
 * TERMINATED.
 *
 * TERMINATED is terminal (set by `TerminateMemberCoverage`); the row is never
 * deleted (§319). Natural expiry is expressed by `effective_to`, not by a
 * status — SUSPENDED / EXPIRED are *eligibility* states (§24), evaluated by
 * Ins.3, not stored here. Backend-only string-constant holder.
 */
final class MemberCoverageStatus
{
    public const ACTIVE = 'ACTIVE';

    public const TERMINATED = 'TERMINATED';

    /** @var list<string> */
    public const ALL = [self::ACTIVE, self::TERMINATED];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid member-coverage status [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        return in_array(strtoupper(trim($value)), self::ALL, true);
    }
}
