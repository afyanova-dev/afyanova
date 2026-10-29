<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Exception\DuplicateRegistrationLicenceException;
use App\Domains\HR\Domain\Model\ProfessionalRegistration;
use App\Domains\HR\Domain\ProfessionalRegistrationRepository;
use App\Domains\HR\Domain\StaffRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles AddProfessionalRegistration command (25-hr-and-workforce-domain.md §3, §7; ADR-206).
 */
final class AddProfessionalRegistrationHandler
{
    public function __construct(
        private readonly ProfessionalRegistrationRepository $registrations,
        private readonly StaffRepository $staff,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AddProfessionalRegistration $command): void
    {
        $this->staff->getById($command->staffId);

        if ($this->registrations->existsByLicence($command->council, $command->licenceNumber)) {
            throw DuplicateRegistrationLicenceException::forLicence($command->council, $command->licenceNumber);
        }

        $registration = ProfessionalRegistration::create(
            tenantId: $this->context->tenantId(),
            staffId: $command->staffId,
            council: $command->council,
            category: $command->category,
            licenceNumber: $command->licenceNumber,
            issuedOn: $command->issuedOn,
            expiresOn: $command->expiresOn,
            registrationId: $command->registrationId,
            notes: $command->notes,
        );

        $this->registrations->save($registration);
    }
}
