<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * How a beneficiary is related to the principal policyholder
 * (21-insurance-and-payer-domain.md §17, §18; ADR-199 D-A).
 *
 * §17 — "the exact relationship vocabulary is configurable"; this is the
 * starting set. `PRIMARY_MEMBER` owns the policy relationship (§18) and has no
 * `principal_member_coverage_id`; every other value references one.
 * Open-coded + SET-validated (the `PayerType` precedent).
 */
final class BeneficiaryRelationship
{
    public const PRIMARY_MEMBER = 'PRIMARY_MEMBER';

    public const SPOUSE = 'SPOUSE';

    public const CHILD = 'CHILD';

    public const DEPENDENT = 'DEPENDENT';

    public const OTHER_DEPENDENT = 'OTHER_DEPENDENT';

    /** @var list<string> */
    public const ALL = [
        self::PRIMARY_MEMBER,
        self::SPOUSE,
        self::CHILD,
        self::DEPENDENT,
        self::OTHER_DEPENDENT,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid beneficiary relationship [%s]. Permitted: %s.',
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
