<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Contracts\Dto\RemittanceSummary;
use App\Domains\Insurance\Domain\RemittanceReader;

/**
 * Orchestrates `GetRemittance` (ADR-205).
 */
final class GetRemittanceHandler
{
    public function __construct(private readonly RemittanceReader $reader) {}

    public function __invoke(GetRemittance $query): RemittanceSummary
    {
        return $this->reader->getById($query->remittanceId);
    }
}
