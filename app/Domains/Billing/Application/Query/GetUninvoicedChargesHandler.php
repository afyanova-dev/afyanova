<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\ChargeSummary;
use App\Domains\Billing\Domain\InvoiceReader;

final class GetUninvoicedChargesHandler
{
    public function __construct(private readonly InvoiceReader $reader) {}

    /**
     * @return list<ChargeSummary>
     */
    public function __invoke(GetUninvoicedCharges $query): array
    {
        return $this->reader->uninvoicedCharges($query->financialAccountId);
    }
}
