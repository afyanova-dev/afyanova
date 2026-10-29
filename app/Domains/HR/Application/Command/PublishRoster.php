<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class PublishRoster implements AuthorizesAgainst
{
    public CarbonImmutable $startDate;

    public CarbonImmutable $endDate;

    public function __construct(
        public string $facilityId,
        string|CarbonImmutable $startDate,
        string|CarbonImmutable $endDate,
    ) {
        $this->startDate = is_string($startDate) ? CarbonImmutable::parse($startDate) : $startDate;
        $this->endDate = is_string($endDate) ? CarbonImmutable::parse($endDate) : $endDate;
    }

    public function policyAbility(): string
    {
        return 'publish';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
