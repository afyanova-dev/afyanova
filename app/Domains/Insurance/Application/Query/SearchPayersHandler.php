<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\PayerSummary;
use App\Domains\Insurance\Domain\PayerReader;

final class SearchPayersHandler
{
    public function __construct(private readonly PayerReader $reader) {}

    /**
     * @return list<PayerSummary>
     */
    public function __invoke(SearchPayers $query): array
    {
        return $this->reader->search($query->payerType, $query->status);
    }
}
