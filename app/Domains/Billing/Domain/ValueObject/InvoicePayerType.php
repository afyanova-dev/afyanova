<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Who an invoice is addressed to (20-billing-and-payments-domain.md §34;
 * ADR-190 INV-5 — was SELF_PAY only; ADR-201 D-B adds INSURANCE).
 *
 * SELF_PAY: `patient_responsibility ≡ net`, no payer. INSURANCE: an
 * Insurance-domain `payer_id` is attached and the patient / payer split is the
 * estimate from `SetInvoiceCoverage`. Backend-only string-constant holder.
 */
final class InvoicePayerType
{
    public const SELF_PAY = 'SELF_PAY';

    public const INSURANCE = 'INSURANCE';

    /** @var list<string> */
    public const ALL = [self::SELF_PAY, self::INSURANCE];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid invoice payer type [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }
}
