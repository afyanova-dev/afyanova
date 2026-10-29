<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one care episode, including its LINKED encounter list (ADR-153 D-F).
 */
#[ProtectedQuery(ability: 'view', subjectType: CareEpisode::class)]
final readonly class GetCareEpisode
{
    public function __construct(public string $careEpisodeId)
    {
        if (! Str::isUuid($careEpisodeId)) {
            throw new InvalidArgumentException('GetCareEpisode::$careEpisodeId must be a UUID (IC §3).');
        }
    }
}
