<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Exception\PatientStickyNoteAlreadyExists;
use App\Domains\Patient\Domain\Model\PatientStickyNote;
use App\Domains\Patient\Domain\PatientRepository;
use App\Domains\Patient\Domain\PatientStickyNoteRepository;
use App\Shared\Tenancy\TenantContext;

final class CreatePatientStickyNoteHandler
{
    public function __construct(
        private readonly PatientStickyNoteRepository $stickyNotes,
        private readonly PatientRepository $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreatePatientStickyNote $command): PatientStickyNote
    {
        $patient = $this->patients->getByIdForUpdate($command->patientId);
        $patient->assertMutable();

        if ($this->stickyNotes->findActiveForPatientForUpdate($command->patientId) !== null) {
            throw new PatientStickyNoteAlreadyExists($command->patientId);
        }

        $note = PatientStickyNote::record(
            id: $command->stickyNoteId,
            tenantId: $this->context->tenantId(),
            patientId: $command->patientId,
            body: $command->body,
            actorId: $command->actorId,
        );

        $this->stickyNotes->save($note);

        return $note;
    }
}
