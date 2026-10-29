<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\Payment;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The allocations of a payment — active + historical (20-billing-and-payments-domain.md
 * §65 / §294; ADR-191 B5-A). `view` gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Payment::class)]
final readonly class GetPaymentAllocations
{
    public function __construct(public string $paymentId)
    {
        if (! Str::isUuid($paymentId)) {
            throw new InvalidArgumentException('GetPaymentAllocations::$paymentId must be a UUID (IC §3).');
        }
    }
}
