<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

final class TranscriptionMedicationNotInCatalog extends DomainException
{
    public function __construct()
    {
        parent::__construct('Select an active medication from the facility catalog before transcribing this prescription.');
    }

    public function errorCode(): string
    {
        return 'transcription_medication_not_in_catalog';
    }
}
