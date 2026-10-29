<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The membership links for one care episode — LINKED first, `added_at` order
 * (26-care-coordination-domain.md §10; ADR-153 D-F).
 */
#[ProtectedQuery(ability: 'view', subjectType: CareEpisode::class)]
final readonly class GetEpisodeEncounters
{
    public function __construct(public string $careEpisodeId)
    {
        if (! Str::isUuid($careEpisodeId)) {
            throw new InvalidArgumentException('GetEpisodeEncounters::$careEpisodeId must be a UUID (IC §3).');
        }
    }
}
