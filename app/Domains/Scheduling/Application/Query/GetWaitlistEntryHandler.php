<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Contracts\Dto\WaitlistEntrySummary;
use App\Domains\Scheduling\Domain\WaitlistEntryReader;

final readonly class GetWaitlistEntryHandler
{
    public function __construct(
        private WaitlistEntryReader $reader,
    ) {}

    public function __invoke(GetWaitlistEntry $query): ?WaitlistEntrySummary
    {
        return $this->reader->getById($query->waitlistEntryId);
    }
}
