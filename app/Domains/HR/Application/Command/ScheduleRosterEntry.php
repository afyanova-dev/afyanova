<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class ScheduleRosterEntry implements AuthorizesAgainst
{
    public string $rosterEntryId;

    public CarbonImmutable $date;

    public function __construct(
        public string $facilityId,
        public string $staffId,
        public string $shiftPatternId,
        string|CarbonImmutable $date,
        public ?string $departmentId = null,
        public ?string $unitId = null,
        public ?string $notes = null,
        ?string $rosterEntryId = null,
    ) {
        $this->rosterEntryId = $rosterEntryId ?? (string) Str::uuid();
        $this->date = is_string($date) ? CarbonImmutable::parse($date) : $date;
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
