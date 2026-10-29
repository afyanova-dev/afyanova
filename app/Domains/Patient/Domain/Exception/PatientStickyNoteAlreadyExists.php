<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Shared\Domain\DomainException;

final class PatientStickyNoteAlreadyExists extends DomainException
{
    public function __construct(string $patientId)
    {
        parent::__construct("Patient [{$patientId}] already has an active sticky note.");
    }

    public function errorCode(): string
    {
        return 'patient_sticky_note_exists';
    }
}
