<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Command;

use App\Domains\Billing\Domain\Money;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * System command: settles an insurance remittance line against a billing invoice
 * (20-billing-and-payments-domain.md §101–§106; ADR-205).
 *
 * Dispatched by the `OnRemittanceLineSettled` event listener.
 * Posts the payer payment, allocates it to the invoice, and requests/applies
 * the contractual allowance adjustment.
 */
final readonly class SettleInsuranceRemittance
{
    /** @var numeric-string */
    public string $paidAmount;

    /** @var numeric-string */
    public string $contractualAdjustment;

    public function __construct(
        public string $invoiceId,
        string $paidAmount,
        string $contractualAdjustment,
        public string $paymentReference,
        public string $settledByUserId,
        public string $remittanceLineId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($invoiceId)) {
            throw new InvalidArgumentException('SettleInsuranceRemittance::$invoiceId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($settledByUserId)) {
            throw new InvalidArgumentException('SettleInsuranceRemittance::$settledByUserId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($remittanceLineId)) {
            throw new InvalidArgumentException('SettleInsuranceRemittance::$remittanceLineId must be a UUID (IC §3).');
        }

        $this->paidAmount = Money::of($paidAmount);
        $this->contractualAdjustment = Money::of($contractualAdjustment);
    }
}
