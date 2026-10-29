<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Payer adjudication determination on an insurance claim
 * (21-insurance-and-payer-domain.md §85; ADR-205).
 *
 * States:
 *   - APPROVED: Payer fully approved reimbursement.
 *   - PARTIALLY_APPROVED: Payer approved part of the claimed amount (with patient responsibility,
 *     contractual adjustment, or partial denial).
 *   - DENIED: Claim was formally adjudicated by payer but reimbursement refused (Invariant 9).
 *   - PENDED: Claim held by payer awaiting additional information / medical records.
 *   - REJECTED: Claim could not be accepted/processed by payer/clearinghouse (technical rejection, Invariant 9).
 */
final class AdjudicationStatus
{
    public const APPROVED = 'APPROVED';

    public const PARTIALLY_APPROVED = 'PARTIALLY_APPROVED';

    public const DENIED = 'DENIED';

    public const PENDED = 'PENDED';

    public const REJECTED = 'REJECTED';

    /** @var list<string> */
    public const ALL = [
        self::APPROVED,
        self::PARTIALLY_APPROVED,
        self::DENIED,
        self::PENDED,
        self::REJECTED,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid adjudication status [%s]. Permitted: %s (§85 / ADR-205).',
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
