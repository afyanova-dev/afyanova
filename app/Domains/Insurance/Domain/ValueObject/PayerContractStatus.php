<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle state of a payer contract (21-insurance-and-payer-domain.md §49; ADR-203).
 *
 * DRAFT → ACTIVE → TERMINATED | EXPIRED.
 * ACTIVE contracts price claims and provide contracted tariffs.
 * TERMINATED and EXPIRED are terminal.
 */
final class PayerContractStatus
{
    public const DRAFT = 'DRAFT';

    public const ACTIVE = 'ACTIVE';

    public const TERMINATED = 'TERMINATED';

    public const EXPIRED = 'EXPIRED';

    /** @var list<string> */
    public const ALL = [
        self::DRAFT,
        self::ACTIVE,
        self::TERMINATED,
        self::EXPIRED,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid payer contract status [%s]. Permitted: %s.',
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
