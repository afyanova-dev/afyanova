<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\ReceiptSummary;
use App\Domains\Billing\Domain\ReceiptReader;

final class GetReceiptHandler
{
    public function __construct(private readonly ReceiptReader $reader) {}

    public function __invoke(GetReceipt $query): ReceiptSummary
    {
        return $this->reader->forPayment($query->paymentId);
    }
}
