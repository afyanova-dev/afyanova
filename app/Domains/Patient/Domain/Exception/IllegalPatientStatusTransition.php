<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Exception;

use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Shared\Domain\DomainException;

/**
 * An attempted patient status transition is not one ADR-116 permits
 * (e.g. DECEASED -> anything, or MERGED -> ACTIVE outside the unmerge process).
 * Maps to HTTP 409 (§15).
 */
final class IllegalPatientStatusTransition extends DomainException
{
    public static function from(PatientStatus $current, PatientStatus $target): self
    {
        return new self(sprintf(
            'A patient cannot move from %s to %s.',
            $current->value,
            $target->value,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_patient_status_transition';
    }
}
