<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\CashSessionSummary;
use App\Domains\Billing\Domain\CashSessionReader;

final class GetCashSessionHandler
{
    public function __construct(private readonly CashSessionReader $reader) {}

    public function __invoke(GetCashSession $query): CashSessionSummary
    {
        return $this->reader->summary($query->cashSessionId);
    }
}
