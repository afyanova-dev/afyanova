<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Reconciliation lifecycle state of a payer remittance advice batch
 * (21-insurance-and-payer-domain.md §98–§103; ADR-205).
 *
 * States:
 *   - RECORDED: Captured in the system with total payment amount; awaiting line matching.
 *   - RECONCILED: All lines matched and settled against claims; reconciled amount matches total.
 *   - DISCREPANT: One or more lines have underpayment/overpayment discrepancies or unmatched balances (§189/§190).
 */
final class RemittanceStatus
{
    public const RECORDED = 'RECORDED';

    public const RECONCILED = 'RECONCILED';

    public const DISCREPANT = 'DISCREPANT';

    /** @var list<string> */
    public const ALL = [
        self::RECORDED,
        self::RECONCILED,
        self::DISCREPANT,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid remittance status [%s]. Permitted: %s (§98 / ADR-205).',
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
