<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\InvoiceSummary;
use App\Domains\Billing\Domain\InvoiceReader;

final class GetAccountInvoicesHandler
{
    public function __construct(private readonly InvoiceReader $reader) {}

    /**
     * @return list<InvoiceSummary>
     */
    public function __invoke(GetAccountInvoices $query): array
    {
        return $this->reader->forAccount($query->financialAccountId);
    }
}
