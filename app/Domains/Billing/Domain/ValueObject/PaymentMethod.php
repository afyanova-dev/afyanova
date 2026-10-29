<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The tender a payment was received in (20-billing-and-payments-domain.md §58;
 * ADR-191 G-6).
 *
 * Bill.5 records CONFIRMED money by an authenticated operator — there is no
 * gateway PENDING / AUTHORIZED lifecycle (§61). `INSURANCE_REMITTANCE` and
 * `CORPORATE_PAYMENT` (§58) are DELIBERATELY EXCLUDED — they belong to the
 * Insurance & Payer domain (ADR-190 D4 / §181 — the SELF_PAY boundary holds).
 * Open-coded + SET-validated (the `InvoiceReason` precedent). `CASH` drives the
 * Bill.6 cash-session count; the other methods are attributed to the session
 * but excluded from it (ADR-191 G-4).
 */
final class PaymentMethod
{
    public const CASH = 'CASH';

    public const CARD = 'CARD';

    public const BANK_TRANSFER = 'BANK_TRANSFER';

    public const MOBILE_MONEY = 'MOBILE_MONEY';

    public const POS = 'POS';

    public const INSURANCE_REMITTANCE = 'INSURANCE_REMITTANCE';

    public const OTHER = 'OTHER';

    /** @var list<string> */
    public const ALL = [
        self::CASH,
        self::CARD,
        self::BANK_TRANSFER,
        self::MOBILE_MONEY,
        self::POS,
        self::INSURANCE_REMITTANCE,
        self::OTHER,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid payment method [%s]. Permitted: %s (§58 / ADR-191 G-6).',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }
}
