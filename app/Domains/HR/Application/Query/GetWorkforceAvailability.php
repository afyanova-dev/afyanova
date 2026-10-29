<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Query;

use App\Domains\HR\Domain\Model\Staff;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;

#[ProtectedQuery(ability: 'view', subjectType: Staff::class)]
final readonly class GetWorkforceAvailability
{
    public ?CarbonImmutable $atTime;

    public function __construct(
        public string $facilityId,
        string|CarbonImmutable|null $atTime = null,
        public ?string $departmentId = null,
        public ?string $unitId = null,
        public ?string $cadreId = null,
    ) {
        $this->atTime = is_string($atTime) ? CarbonImmutable::parse($atTime) : $atTime;
    }
}
