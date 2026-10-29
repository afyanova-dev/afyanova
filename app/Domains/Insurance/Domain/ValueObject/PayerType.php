<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The commercial model of a payer organization
 * (21-insurance-and-payer-domain.md §10, §140; ADR-198 D-G).
 *
 * `SELF_PAY` is deliberately absent — self-pay is "Payer: None / Patient"
 * (§141 / §285), not an external payer organization; Billing's
 * `payer_type = 'SELF_PAY'` (ADR-190) stays the "no payer" sentinel.
 *
 * Open-coded + SET-validated on the write path (the `BillingServiceCategory` /
 * `PharmacyOrderReason` precedent — NOT a PHP enum, NOT #[TypeScript]).
 */
final class PayerType
{
    public const INSURANCE = 'INSURANCE';

    public const GOVERNMENT_PROGRAM = 'GOVERNMENT_PROGRAM';

    public const CORPORATE = 'CORPORATE';

    public const EMPLOYER = 'EMPLOYER';

    public const SELF_FUNDED = 'SELF_FUNDED';

    public const OTHER_CONTRACTED_PAYER = 'OTHER_CONTRACTED_PAYER';

    /** @var list<string> */
    public const ALL = [
        self::INSURANCE,
        self::GOVERNMENT_PROGRAM,
        self::CORPORATE,
        self::EMPLOYER,
        self::SELF_FUNDED,
        self::OTHER_CONTRACTED_PAYER,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid payer type [%s]. Permitted: %s.',
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
