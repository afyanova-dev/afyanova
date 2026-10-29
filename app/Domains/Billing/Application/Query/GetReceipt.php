<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\Payment;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read a payment's receipt — the reprint source (20-billing-and-payments-domain.md
 * §93; ADR-191 B5-C). `view` gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Payment::class)]
final readonly class GetReceipt
{
    public function __construct(public string $paymentId)
    {
        if (! Str::isUuid($paymentId)) {
            throw new InvalidArgumentException('GetReceipt::$paymentId must be a UUID (IC §3).');
        }
    }
}
