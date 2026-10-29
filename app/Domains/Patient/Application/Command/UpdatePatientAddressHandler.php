<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Model\PatientAddress;
use App\Domains\Patient\Domain\PatientAddressRepository;
use App\Domains\Patient\Domain\PatientRepository;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;

/**
 * PatientAddressRepository is listed first so
 * AuthorizeCommand::ownerRepositoryType() (IC §5.1) resolves the aggregate as
 * PatientAddress for the class-level `updateAddress` check. PatientRepository
 * is the tenant-scoped existence guard for $patientId on the create branch.
 */
final class UpdatePatientAddressHandler
{
    public function __construct(
        private readonly PatientAddressRepository $addresses,
        private readonly PatientRepository $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(UpdatePatientAddress $command): UpdatePatientAddressResult
    {
        $existing = $this->addresses->findById($command->addressId); // RLS-scoped

        if ($existing !== null) {
            if ((string) $existing->patient_id !== $command->patientId) {
                throw NotFound::aggregate(PatientAddress::class, $command->addressId);
            }

            $this->patients->getById($command->patientId)->assertMutable(); // ADR-128 M-9 (blocks MERGED)

            $existing->revise(
                type: $command->type,
                region: $command->region,
                district: $command->district,
                ward: $command->ward,
                streetOrVillage: $command->streetOrVillage,
                postalCode: $command->postalCode,
                notes: $command->notes,
                isActive: $command->isActive,
                actorId: $command->actorId,
            );
            $this->addresses->save($existing);

            return new UpdatePatientAddressResult((string) $existing->id, false);
        }

        $this->patients->getById($command->patientId)->assertMutable(); // NotFound if missing / cross-tenant; ADR-128 M-9 (blocks MERGED)

        $address = PatientAddress::open(
            id: $command->addressId,
            tenantId: $this->context->tenantId(),
            patientId: $command->patientId,
            type: $command->type,
            region: $command->region,
            district: $command->district,
            ward: $command->ward,
            streetOrVillage: $command->streetOrVillage,
            postalCode: $command->postalCode,
            notes: $command->notes,
            recordedByUserId: $command->actorId,
        );
        $this->addresses->save($address);

        return new UpdatePatientAddressResult((string) $address->id, true);
    }
}
