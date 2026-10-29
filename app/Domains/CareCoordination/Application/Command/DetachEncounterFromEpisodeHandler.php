<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\CareEpisodeEncounterRepository;
use App\Domains\CareCoordination\Domain\Service\CareEpisodeLifecycle;

/**
 * Orchestrates `DetachEncounterFromEpisode` (ADR-153 D-C/D-D).
 *
 * The link is loaded `getByIdForUpdate` (row lock — the ADR-128 M-13 /
 * ADR-152 AmendOperativeNote precedent): a detach on an already-DETACHED link
 * dirties no aggregate attribute (`status` re-assignment is a no-op), so the
 * optimistic `version` check cannot serialize concurrent detaches — the FOR
 * UPDATE lock does. The lifecycle guard rejects the already-DETACHED case
 * loudly anyway (409).
 */
final class DetachEncounterFromEpisodeHandler
{
    public function __construct(
        private readonly CareEpisodeEncounterRepository $links,
        private readonly CareEpisodeLifecycle $lifecycle,
    ) {}

    public function __invoke(DetachEncounterFromEpisode $command): void
    {
        $link = $this->links->getByIdForUpdate($command->linkId);

        $this->lifecycle->assertCanDetach((string) $link->status);

        $link->detach($command->actorId, trim($command->reason));

        $this->links->save($link);
    }
}
