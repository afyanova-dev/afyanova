<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\PatientRepository;
use App\Domains\Patient\Domain\PatientStickyNoteRepository;

final class UpdatePatientStickyNoteHandler
{
    public function __construct(
        private readonly PatientStickyNoteRepository $stickyNotes,
        private readonly PatientRepository $patients,
    ) {}

    public function __invoke(UpdatePatientStickyNote $command): void
    {
        $note = $this->stickyNotes->getByIdForUpdate($command->stickyNoteId);
        $this->patients->getById((string) $note->patient_id)->assertMutable();
        $note->revise($command->body, $command->actorId);
        $this->stickyNotes->save($note);
    }
}
