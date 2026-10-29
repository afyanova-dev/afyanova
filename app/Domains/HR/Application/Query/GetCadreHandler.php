<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\CadreSummary;
use App\Domains\HR\Domain\CadrePositionReader;

final class GetCadreHandler
{
    public function __construct(private readonly CadrePositionReader $reader) {}

    public function __invoke(GetCadre $query): CadreSummary
    {
        return $this->reader->cadreSummary($query->cadreId);
    }
}
