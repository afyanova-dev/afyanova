<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\CareEpisodeRepository;
use App\Domains\CareCoordination\Domain\Service\CareEpisodeLifecycle;

final class CloseCareEpisodeHandler
{
    public function __construct(
        private readonly CareEpisodeRepository $episodes,
        private readonly CareEpisodeLifecycle $lifecycle,
    ) {}

    public function __invoke(CloseCareEpisode $command): void
    {
        $episode = $this->episodes->getById($command->episodeId);

        $this->lifecycle->assertCanClose((string) $episode->status);

        $episode->close();

        $this->episodes->save($episode);
    }
}
