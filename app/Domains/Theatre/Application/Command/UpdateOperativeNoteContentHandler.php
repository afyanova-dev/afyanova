<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Domains\Theatre\Domain\OperativeNoteRepository;
use App\Domains\Theatre\Domain\Service\OperativeNoteLifecycle;

final class UpdateOperativeNoteContentHandler
{
    public function __construct(
        private readonly OperativeNoteRepository $notes,
        private readonly OperativeNoteLifecycle $lifecycle,
    ) {}

    public function __invoke(UpdateOperativeNoteContent $command): void
    {
        $note = $this->notes->getById($command->noteId);

        $this->lifecycle->assertCanUpdateContent((string) $note->status);

        $note->updateContent($command->content);

        $this->notes->save($note);
    }
}
