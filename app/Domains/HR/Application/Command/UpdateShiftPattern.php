<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;

final readonly class UpdateShiftPattern implements AuthorizesAgainst
{
    public function __construct(
        public string $shiftPatternId,
        public string $name,
        public string $startTime,
        public string $endTime,
        public int $breakMinutes = 0,
        public bool $isOvernight = false,
        public bool $isActive = true,
    ) {}

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
