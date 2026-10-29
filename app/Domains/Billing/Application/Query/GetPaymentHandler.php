<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\PaymentSummary;
use App\Domains\Billing\Domain\PaymentReader;

final class GetPaymentHandler
{
    public function __construct(private readonly PaymentReader $reader) {}

    public function __invoke(GetPayment $query): PaymentSummary
    {
        return $this->reader->summary($query->paymentId);
    }
}
