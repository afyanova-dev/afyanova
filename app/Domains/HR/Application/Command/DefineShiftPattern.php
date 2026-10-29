<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;

final readonly class DefineShiftPattern implements AuthorizesAgainst
{
    public string $shiftPatternId;

    public function __construct(
        public string $facilityId,
        public string $code,
        public string $name,
        public string $startTime,
        public string $endTime,
        public int $breakMinutes = 0,
        public bool $isOvernight = false,
        ?string $shiftPatternId = null,
    ) {
        $this->shiftPatternId = $shiftPatternId ?? (string) Str::uuid();
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
