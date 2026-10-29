<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\WriteOffSummary;
use App\Domains\Billing\Domain\WriteOffReader;

final class GetPendingWriteOffsHandler
{
    public function __construct(private readonly WriteOffReader $reader) {}

    /**
     * @return list<WriteOffSummary>
     */
    public function __invoke(GetPendingWriteOffs $query): array
    {
        return $this->reader->pending($query->facilityId);
    }
}
