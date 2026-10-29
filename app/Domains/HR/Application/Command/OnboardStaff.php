<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class OnboardStaff implements AuthorizesAgainst
{
    public CarbonImmutable $joinedDate;

    public function __construct(
        public string $staffId,
        string|CarbonImmutable $joinedDate,
        public ?string $cadreId = null,
        public ?string $positionId = null,
    ) {
        $this->joinedDate = is_string($joinedDate)
            ? CarbonImmutable::parse($joinedDate)
            : $joinedDate;
    }

    public function policyAbility(): string
    {
        return 'onboard';
    }

    public function subjectId(): string
    {
        return $this->staffId;
    }
}
