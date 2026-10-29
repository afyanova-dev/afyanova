<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\PaymentAllocationSummary;
use App\Domains\Billing\Domain\PaymentAllocationReader;

final class GetPaymentAllocationsHandler
{
    public function __construct(private readonly PaymentAllocationReader $reader) {}

    /**
     * @return list<PaymentAllocationSummary>
     */
    public function __invoke(GetPaymentAllocations $query): array
    {
        return $this->reader->forPayment($query->paymentId);
    }
}
