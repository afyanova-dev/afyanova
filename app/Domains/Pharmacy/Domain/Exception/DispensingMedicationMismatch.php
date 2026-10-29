<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

final class DispensingMedicationMismatch extends DomainException
{
    public function __construct()
    {
        parent::__construct('The dispensing item must match the medication selected for this external paper prescription.');
    }

    public function errorCode(): string
    {
        return 'dispensing_medication_mismatch';
    }
}
