<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Contracts\Dto\WriteOffSummary;
use App\Domains\Billing\Domain\WriteOffReader;

final class GetWriteOffHandler
{
    public function __construct(private readonly WriteOffReader $reader) {}

    public function __invoke(GetWriteOff $query): WriteOffSummary
    {
        return $this->reader->summary($query->writeOffId);
    }
}
