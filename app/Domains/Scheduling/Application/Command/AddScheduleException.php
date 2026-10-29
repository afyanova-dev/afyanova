<?php

declare(strict_types=1);

namespace App\Domains\Scheduling\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class AddScheduleException implements AuthorizesAgainst
{
    public string $exceptionId;

    public CarbonImmutable $exceptionDate;

    public function __construct(
        public string $facilityId,
        public string $resourceId,
        string|CarbonImmutable $exceptionDate,
        public string $reason,
        public bool $isAvailable = false,
        public ?string $startTime = null,
        public ?string $endTime = null,
        ?string $exceptionId = null,
    ) {
        $this->exceptionId = $exceptionId ?? (string) Str::uuid();
        $this->exceptionDate = is_string($exceptionDate) ? CarbonImmutable::parse($exceptionDate) : $exceptionDate;
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
