<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\PositionSummary;
use App\Domains\HR\Domain\CadrePositionReader;

final class GetPositionsHandler
{
    public function __construct(private readonly CadrePositionReader $reader) {}

    /**
     * @return list<PositionSummary>
     */
    public function __invoke(GetPositions $query): array
    {
        return $this->reader->allPositions(
            cadreId: $query->cadreId,
            activeOnly: $query->activeOnly,
        );
    }
}
