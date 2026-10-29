<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle state of a payer plan
 * (21-insurance-and-payer-domain.md §12, §319; ADR-198 D-H): ACTIVE | INACTIVE.
 *
 * The `PayerStatus` shape — a two-state config toggle. INACTIVE = not available
 * for new member coverage; historical coverage / claims are never deleted
 * (§319). A terminal `RETIRED` is added additively if a later increment needs
 * it (ADR-198 Alternatives). Backend-only string-constant holder.
 */
final class PlanStatus
{
    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    /** @var list<string> */
    public const ALL = [self::ACTIVE, self::INACTIVE];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid plan status [%s]. Permitted: %s.',
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
