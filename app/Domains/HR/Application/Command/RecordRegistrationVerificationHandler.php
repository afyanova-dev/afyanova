<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ProfessionalRegistrationRepository;

/**
 * Handles RecordRegistrationVerification command (25-hr-and-workforce-domain.md §3, §8; ADR-206).
 */
final class RecordRegistrationVerificationHandler
{
    public function __construct(
        private readonly ProfessionalRegistrationRepository $registrations,
    ) {}

    public function __invoke(RecordRegistrationVerification $command): void
    {
        $registration = $this->registrations->getByIdForUpdate($command->registrationId);

        $registration->recordVerification($command->verifiedAt, $command->verificationSource, $command->notes);

        $this->registrations->save($registration);
    }
}
