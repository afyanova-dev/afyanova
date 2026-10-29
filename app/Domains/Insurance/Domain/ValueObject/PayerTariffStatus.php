<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle state of a payer tariff row (ADR-203; domain 21 §114–§116):
 * CURRENT | SUPERSEDED | ENDED (the bill_tariffs / ins_plan_coverage_rules pattern).
 *
 * CURRENT = the open row for its (contract, category) scope.
 * SUPERSEDED = closed by a later SetPayerTariff.
 * ENDED = closed by EndPayerTariff.
 * Rows are never deleted (historical contract pricing preserved).
 */
final class PayerTariffStatus
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
                'Invalid payer tariff status [%s]. Permitted: %s.',
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
