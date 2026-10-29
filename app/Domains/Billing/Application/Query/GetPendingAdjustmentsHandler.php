<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\AdjustmentSummary;
use App\Domains\Billing\Domain\AdjustmentReader;

final class GetPendingAdjustmentsHandler
{
    public function __construct(private readonly AdjustmentReader $reader) {}

    /**
     * @return list<AdjustmentSummary>
     */
    public function __invoke(GetPendingAdjustments $query): array
    {
        return $this->reader->pending($query->facilityId);
    }
}
