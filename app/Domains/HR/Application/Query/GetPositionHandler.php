<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\PositionSummary;
use App\Domains\HR\Domain\CadrePositionReader;

final class GetPositionHandler
{
    public function __construct(private readonly CadrePositionReader $reader) {}

    public function __invoke(GetPosition $query): PositionSummary
    {
        return $this->reader->positionSummary($query->positionId);
    }
}
