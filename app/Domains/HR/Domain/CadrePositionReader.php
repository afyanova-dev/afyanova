<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\CadreSummary;
use App\Domains\HR\Contracts\Dto\PositionSummary;

/**
 * Read-side port for Cadre and Position queries (25-hr-and-workforce-domain.md §4).
 */
interface CadrePositionReader
{
    public function cadreSummary(string $cadreId): CadreSummary;

    /**
     * @return list<CadreSummary>
     */
    public function allCadres(?bool $activeOnly = true): array;

    public function positionSummary(string $positionId): PositionSummary;

    /**
     * @return list<PositionSummary>
     */
    public function allPositions(?string $cadreId = null, ?bool $activeOnly = true): array;
}
