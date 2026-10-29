<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\ShiftPattern;
use App\Shared\Bus\ProtectedQuery;

#[ProtectedQuery(ability: 'view', subjectType: ShiftPattern::class)]
final readonly class GetShiftPatterns
{
    public function __construct(
        public string $facilityId,
        public bool $activeOnly = true,
    ) {}
}
