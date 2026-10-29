<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\Invoice;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one invoice + its lines + the derived current balance
 * (20-billing-and-payments-domain.md §34 / §139; ADR-190 D-D). `view` gates on
 * the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Invoice::class)]
final readonly class GetInvoice
{
    public function __construct(public string $invoiceId)
    {
        if (! Str::isUuid($invoiceId)) {
            throw new InvalidArgumentException('GetInvoice::$invoiceId must be a UUID (IC §3).');
        }
    }
}
