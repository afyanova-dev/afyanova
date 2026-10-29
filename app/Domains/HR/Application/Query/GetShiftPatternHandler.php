<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Contracts\Dto\ShiftPatternSummary;
use App\Domains\HR\Domain\ShiftPatternReader;

final class GetShiftPatternHandler
{
    public function __construct(private readonly ShiftPatternReader $reader) {}

    public function __invoke(GetShiftPattern $query): ShiftPatternSummary
    {
        return $this->reader->summary($query->shiftPatternId);
    }
}
