<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Query;

use App\Domains\Scheduling\Domain\Model\Schedule;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: Schedule::class)]
final readonly class GetResourceSchedule
{
    public function __construct(
        public string $resourceId,
    ) {}
}
