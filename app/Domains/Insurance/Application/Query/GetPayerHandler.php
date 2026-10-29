<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerSummary;
use App\Domains\Insurance\Domain\PayerReader;

final class GetPayerHandler
{
    public function __construct(private readonly PayerReader $reader) {}

    public function __invoke(GetPayer $query): PayerSummary
    {
        return $this->reader->summary($query->payerId);
    }
}
