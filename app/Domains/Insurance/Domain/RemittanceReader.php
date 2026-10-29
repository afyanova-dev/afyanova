<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Contracts\Dto\RemittanceSummary;

/**
 * Read-side port for remittance projections (IC §9; ADR-205).
 */
interface RemittanceReader
{
    public function getById(string $id): RemittanceSummary;

    public function getByRemittanceNumber(string $remittanceNumber): ?RemittanceSummary;

    /**
     * @return list<RemittanceSummary>
     */
    public function search(?string $payerId = null, ?string $status = null): array;
}
