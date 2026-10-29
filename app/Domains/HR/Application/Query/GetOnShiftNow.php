<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\RosterEntry;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;

#[ProtectedQuery(ability: 'view', subjectType: RosterEntry::class)]
final readonly class GetOnShiftNow
{
    public ?CarbonImmutable $atTime;

    public function __construct(
        public string $facilityId,
        public ?string $unitId = null,
        string|CarbonImmutable|null $atTime = null,
    ) {
        $this->atTime = is_string($atTime) ? CarbonImmutable::parse($atTime) : $atTime;
    }
}
