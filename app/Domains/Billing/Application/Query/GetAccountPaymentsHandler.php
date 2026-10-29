<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\PaymentSummary;
use App\Domains\Billing\Domain\PaymentReader;

final class GetAccountPaymentsHandler
{
    public function __construct(private readonly PaymentReader $reader) {}

    /**
     * @return list<PaymentSummary>
     */
    public function __invoke(GetAccountPayments $query): array
    {
        return $this->reader->forAccount($query->financialAccountId);
    }
}
