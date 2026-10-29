<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\AdjustmentSummary;
use App\Domains\Billing\Domain\AdjustmentReader;

final class GetAdjustmentHandler
{
    public function __construct(private readonly AdjustmentReader $reader) {}

    public function __invoke(GetAdjustment $query): AdjustmentSummary
    {
        return $this->reader->summary($query->adjustmentId);
    }
}
