<?php

declare(strict_types=1);

namespace App\Domains\Patient\Authorization;

use App\Domains\Patient\Application\Command\ArchivePatientStickyNote;
use App\Domains\Patient\Application\Command\CreatePatientStickyNote;
use App\Domains\Patient\Application\Command\UpdatePatientStickyNote;
use App\Domains\Patient\Domain\Model\PatientStickyNote;
use App\Models\User;
use App\Shared\Authorization\BasePolicy;

final class PatientStickyNotePolicy extends BasePolicy
{
    public function createStickyNote(User $user, ?CreatePatientStickyNote $command = null): bool
    {
        return $this->context->hasTenant()
            && (string) $user->tenant_id === $this->context->tenantId()
            && $this->permits($user, 'patient.patients.update');
    }

    public function updateStickyNote(
        User $user,
        PatientStickyNote $note,
        ?UpdatePatientStickyNote $command = null,
    ): bool {
        return $note->archived_at === null
            && $this->sameTenant($user, (string) $note->tenant_id)
            && $this->permits($user, 'patient.patients.update');
    }

    public function archiveStickyNote(
        User $user,
        PatientStickyNote $note,
        ?ArchivePatientStickyNote $command = null,
    ): bool {
        return $note->archived_at === null
            && $this->sameTenant($user, (string) $note->tenant_id)
            && $this->permits($user, 'patient.patients.update');
    }
}
