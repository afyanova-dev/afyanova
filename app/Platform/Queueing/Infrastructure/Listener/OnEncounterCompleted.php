<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Infrastructure\Listener;

use App\Domains\Encounter\Contracts\Event\EncounterCompleted;
use App\Platform\Queueing\Application\Command\CancelEncounterQueueEntriesCommand;
use App\Shared\Bus\CommandBus;

final class OnEncounterCompleted
{
    public function __construct(private readonly CommandBus $bus) {}

    public function __invoke(EncounterCompleted $event): void
    {
        $this->bus->dispatch(new CancelEncounterQueueEntriesCommand(
            encounterId: $event->encounterId,
            reason: "Encounter completed with disposition: {$event->disposition}",
        ));
    }
}
