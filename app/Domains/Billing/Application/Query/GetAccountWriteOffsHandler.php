<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\WriteOffSummary;
use App\Domains\Billing\Domain\WriteOffReader;

final class GetAccountWriteOffsHandler
{
    public function __construct(private readonly WriteOffReader $reader) {}

    /**
     * @return list<WriteOffSummary>
     */
    public function __invoke(GetAccountWriteOffs $query): array
    {
        return $this->reader->forAccount($query->financialAccountId);
    }
}
