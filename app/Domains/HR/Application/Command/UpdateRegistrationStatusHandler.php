<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ProfessionalRegistrationRepository;

/**
 * Handles UpdateRegistrationStatus command (25-hr-and-workforce-domain.md §3, §8; ADR-206).
 */
final class UpdateRegistrationStatusHandler
{
    public function __construct(
        private readonly ProfessionalRegistrationRepository $registrations,
    ) {}

    public function __invoke(UpdateRegistrationStatus $command): void
    {
        $registration = $this->registrations->getByIdForUpdate($command->registrationId);

        $registration->updateStatus($command->status, $command->reason);

        $this->registrations->save($registration);
    }
}
