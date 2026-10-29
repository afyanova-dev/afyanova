<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\Invoice;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The invoices on one Financial Account, newest first
 * (20-billing-and-payments-domain.md §34; ADR-190 D-D). `view` gates on the
 * coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Invoice::class)]
final readonly class GetAccountInvoices
{
    public function __construct(public string $financialAccountId)
    {
        if (! Str::isUuid($financialAccountId)) {
            throw new InvalidArgumentException('GetAccountInvoices::$financialAccountId must be a UUID (IC §3).');
        }
    }
}
