<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Exception\PatientAlreadyRegisteredAtFacility;
use App\Domains\Patient\Domain\Model\PatientRegistration;
use App\Domains\Patient\Domain\PatientRegistrationRepository;
use App\Domains\Patient\Domain\PatientRepository;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;

final class RegisterPatientAtFacilityHandler
{
    public function __construct(
        private readonly PatientRegistrationRepository $registrations,
        private readonly PatientRepository $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RegisterPatientAtFacility $command): void
    {
        // tenant-scoped existence check — throws NotFound for a missing or
        // cross-tenant patient (mirrors 11-encounter-domain.md Invariant 13).
        // ADR-128 M-11: a MERGED identity must not gain a new facility registration.
        $this->patients->getById($command->patientId)->assertMutable();

        $registration = PatientRegistration::register(
            tenantId: $this->context->tenantId(),
            facilityId: $command->facilityId,
            patientId: $command->patientId,
        );

        try {
            $this->registrations->save($registration);
        } catch (UniqueConstraintViolationException) {
            // IC §9.1 — "at most one X": the DB constraint is the final guard;
            // the handler converts the raw violation into a DomainException.
            throw PatientAlreadyRegisteredAtFacility::forPatientAndFacility(
                $command->patientId,
                $command->facilityId,
            );
        }
    }
}
