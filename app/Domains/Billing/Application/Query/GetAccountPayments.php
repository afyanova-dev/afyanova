<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\Payment;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The payments on one Financial Account, newest first — also the soft
 * duplicate-payment check surface (20-billing-and-payments-domain.md §64;
 * ADR-191 B5-B). `view` gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Payment::class)]
final readonly class GetAccountPayments
{
    public function __construct(public string $financialAccountId)
    {
        if (! Str::isUuid($financialAccountId)) {
            throw new InvalidArgumentException('GetAccountPayments::$financialAccountId must be a UUID (IC §3).');
        }
    }
}
