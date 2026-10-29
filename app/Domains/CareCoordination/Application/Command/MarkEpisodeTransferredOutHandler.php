<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\CareEpisodeRepository;
use App\Domains\CareCoordination\Domain\Service\CareEpisodeLifecycle;

final class MarkEpisodeTransferredOutHandler
{
    public function __construct(
        private readonly CareEpisodeRepository $episodes,
        private readonly CareEpisodeLifecycle $lifecycle,
    ) {}

    public function __invoke(MarkEpisodeTransferredOut $command): void
    {
        $episode = $this->episodes->getById($command->episodeId);

        $this->lifecycle->assertCanTransferOut((string) $episode->status);

        $episode->markTransferredOut();

        $this->episodes->save($episode);
    }
}
