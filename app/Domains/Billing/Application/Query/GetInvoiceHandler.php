<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\InvoiceSummary;
use App\Domains\Billing\Domain\InvoiceReader;

final class GetInvoiceHandler
{
    public function __construct(private readonly InvoiceReader $reader) {}

    public function __invoke(GetInvoice $query): InvoiceSummary
    {
        return $this->reader->summary($query->invoiceId);
    }
}
