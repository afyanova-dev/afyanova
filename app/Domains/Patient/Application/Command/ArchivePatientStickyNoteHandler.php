<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\PatientRepository;
use App\Domains\Patient\Domain\PatientStickyNoteRepository;

final class ArchivePatientStickyNoteHandler
{
    public function __construct(
        private readonly PatientStickyNoteRepository $stickyNotes,
        private readonly PatientRepository $patients,
    ) {}

    public function __invoke(ArchivePatientStickyNote $command): void
    {
        $note = $this->stickyNotes->getByIdForUpdate($command->stickyNoteId);
        $this->patients->getById((string) $note->patient_id)->assertMutable();
        $note->archive($command->actorId);
        $this->stickyNotes->save($note);
    }
}
