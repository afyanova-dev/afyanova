<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Application\Query;

use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Count facility encounters that recorded an arrival during a facility day.
 *
 * A date is matched against arrived_at only, so encounters without a recorded
 * arrival are not treated as arrivals merely because they were created that day.
 */
#[ProtectedQuery(ability: 'view', subjectType: Encounter::class)]
final readonly class CountFacilityEncounters
{
    public CarbonImmutable $date;

    /**
     * @param  list<EncounterStatus|string>|null  $statuses
     */
    public function __construct(
        public string $facilityId,
        CarbonImmutable|string $date,
        public ?array $statuses = null,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('CountFacilityEncounters::$facilityId must be a UUID.');
        }

        $this->date = is_string($date) ? CarbonImmutable::parse($date) : $date;
    }
}
