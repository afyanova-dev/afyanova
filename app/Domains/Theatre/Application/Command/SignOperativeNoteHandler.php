<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\OperativeNoteRepository;
use App\Domains\Theatre\Domain\Service\OperativeNoteLifecycle;

final class SignOperativeNoteHandler
{
    public function __construct(
        private readonly OperativeNoteRepository $notes,
        private readonly OperativeNoteLifecycle $lifecycle,
    ) {}

    public function __invoke(SignOperativeNote $command): void
    {
        $note = $this->notes->getById($command->noteId);

        $this->lifecycle->assertCanSign((string) $note->status);

        $note->sign($command->actorId);

        $this->notes->save($note);
    }
}
