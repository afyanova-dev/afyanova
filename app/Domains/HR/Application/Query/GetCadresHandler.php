<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\CadreSummary;
use App\Domains\HR\Domain\CadrePositionReader;

final class GetCadresHandler
{
    public function __construct(private readonly CadrePositionReader $reader) {}

    /**
     * @return list<CadreSummary>
     */
    public function __invoke(GetCadres $query): array
    {
        return $this->reader->allCadres(activeOnly: $query->activeOnly);
    }
}
