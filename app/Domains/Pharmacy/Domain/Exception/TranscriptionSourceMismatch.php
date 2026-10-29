<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AcceptTranscription` targeted an electronically-ingested prescription.
 * Electronic rows accept through `AcceptPrescription` only — the two
 * origination paths never cross. Maps to HTTP 409.
 */
final class TranscriptionSourceMismatch extends DomainException
{
    public function __construct(string $prescriptionId)
    {
        parent::__construct(sprintf(
            'Prescription [%s] came from the clinical system — accept it through the clinical path, not transcription.',
            $prescriptionId,
        ));
    }

    public function errorCode(): string
    {
        return 'transcription_source_mismatch';
    }
}
