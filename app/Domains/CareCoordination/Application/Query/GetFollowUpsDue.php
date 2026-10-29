<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Shared\Bus\ProtectedQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * PENDING follow-ups at a facility whose due window overlaps [from, to]
 * (26-care-coordination-domain.md §10; ADR-154 D-F) — feeds Scheduling /
 * Notifications.
 */
#[ProtectedQuery(ability: 'view', subjectType: FollowUp::class)]
final readonly class GetFollowUpsDue
{
    public function __construct(
        public string $facilityId,
        public string $from,
        public string $to,
    ) {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetFollowUpsDue::$facilityId must be a UUID (IC §3).');
        }

        try {
            $from = CarbonImmutable::parse($this->from);
            $to = CarbonImmutable::parse($this->to);
        } catch (Throwable) {
            throw new InvalidArgumentException('GetFollowUpsDue::$from / $to must be ISO-8601 dates.');
        }

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('GetFollowUpsDue::$to must not be before $from.');
        }
    }
}
