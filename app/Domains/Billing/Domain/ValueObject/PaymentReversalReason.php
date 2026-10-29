<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coded reason set for reversing a Payment
 * (20-billing-and-payments-domain.md §70; ADR-191 B5-C).
 *
 * A payment reversal REMOVES the accounting effect of a payment (§72) — a
 * cashier error, a bounced cheque, a wrong account. It is NOT a refund
 * (money returned to the payer — deferred). Open-coded + SET-validated.
 */
final class PaymentReversalReason
{
    public const REASONS = [
        'PAYMENT_ERROR',
        'WRONG_ACCOUNT',
        'BOUNCED',
        'DUPLICATE',
        'OTHER',
    ];

    public static function assert(string $code): string
    {
        $code = strtoupper(trim($code));

        if (! in_array($code, self::REASONS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid payment reversal reason [%s]. Permitted: %s (§70 / ADR-191 B5-C).',
                $code,
                implode(', ', self::REASONS),
            ));
        }

        return $code;
    }
}
