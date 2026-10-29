<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\RemittanceSummary;
use App\Domains\Insurance\Domain\RemittanceReader;

/**
 * Orchestrates `SearchRemittances` (ADR-205).
 */
final class SearchRemittancesHandler
{
    public function __construct(private readonly RemittanceReader $reader) {}

    /**
     * @return list<RemittanceSummary>
     */
    public function __invoke(SearchRemittances $query): array
    {
        return $this->reader->search($query->payerId, $query->status);
    }
}
