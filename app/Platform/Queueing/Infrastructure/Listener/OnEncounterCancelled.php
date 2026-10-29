<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Listener;

use App\Domains\Encounter\Contracts\Event\EncounterCancelled;
use App\Platform\Queueing\Application\Command\CancelEncounterQueueEntriesCommand;
use App\Shared\Bus\CommandBus;

final class OnEncounterCancelled
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(EncounterCancelled $event): void
    {
        $this->bus->dispatch(new CancelEncounterQueueEntriesCommand(
            encounterId: $event->encounterId,
            reason: 'Encounter cancelled',
        ));
    }
}
