<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded reason set for a write-off (20-billing-and-payments-domain.md §78;
 * ADR-191 D7-A). A write-off records that an outstanding amount will not be
 * collected under the applicable policy — it is NOT a refund (§79). Open-coded,
 * SET-validated.
 */
final class WriteOffReason
{
    public const REASONS = [
        'APPROVED_BAD_DEBT',
        'ADMINISTRATIVE',
        'CONTRACTUAL',
        'OTHER',
    ];

    public static function assert(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid write-off reason [%s]. Permitted: %s (§78).',
                $code,
                implode(', ', self::REASONS),
            ));
        }

        return $code;
    }
}
