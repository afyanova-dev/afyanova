<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\CashSession;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one cash session — the drawer state / reconciliation view
 * (20-billing-and-payments-domain.md §153–§157; ADR-191 C6). `view` gates on
 * the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: CashSession::class)]
final readonly class GetCashSession
{
    public function __construct(public string $cashSessionId)
    {
        if (! Str::isUuid($cashSessionId)) {
            throw new InvalidArgumentException('GetCashSession::$cashSessionId must be a UUID (IC §3).');
        }
    }
}
