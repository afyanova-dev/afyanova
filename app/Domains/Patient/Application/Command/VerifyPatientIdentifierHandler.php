<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Exception\DuplicateVerifiedIdentifier;
use App\Domains\Patient\Domain\PatientIdentifierRepository;
use App\Domains\Patient\Domain\PatientRepository;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * PatientIdentifierRepository is listed first so
 * AuthorizeCommand::ownerRepositoryType() (IC §5.1) resolves the subject
 * aggregate as PatientIdentifier. PatientRepository is used only to enforce
 * ADR-128 M-9 (a MERGED identity's identifiers are not independently mutable).
 */
final class VerifyPatientIdentifierHandler
{
    public function __construct(
        private readonly PatientIdentifierRepository $identifiers,
        private readonly PatientRepository $patients,
    ) {}

    public function __invoke(VerifyPatientIdentifier $command): void
    {
        $identifier = $this->identifiers->getById($command->identifierId); // RLS-scoped; NotFound if missing / cross-tenant

        $this->patients->getById((string) $identifier->patient_id)->assertMutable(); // ADR-128 M-9 (blocks MERGED)

        $identifier->verify($command->actorId, $command->verificationSource); // §42 guard; records PatientIdentifierVerified

        try {
            $this->identifiers->save($identifier);
        } catch (UniqueConstraintViolationException) {
            // partial unique index: another patient already holds this value VERIFIED (ADR-117)
            throw DuplicateVerifiedIdentifier::for($identifier->identifier_type, $identifier->normalized_value);
        }
    }
}
