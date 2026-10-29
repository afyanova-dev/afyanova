<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `TranscribePaperPrescription` named a patient that is not registered at
 * the active facility. Maps to HTTP 409.
 */
final class TranscriptionContextUnresolved extends DomainException
{
    public function __construct(string $patientId, string $facilityId)
    {
        parent::__construct(
            'The selected patient is not registered at the active facility. Search for the correct patient or create a pharmacy-only record.',
        );
    }

    public function errorCode(): string
    {
        return 'transcription_context_unresolved';
    }
}
