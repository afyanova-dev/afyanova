<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Model\PatientContact;
use App\Domains\Patient\Domain\PatientContactRepository;
use App\Domains\Patient\Domain\PatientRepository;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;

/**
 * PatientContactRepository is listed first so
 * AuthorizeCommand::ownerRepositoryType() (IC §5.1) resolves the aggregate as
 * PatientContact for the class-level `updateContact` check. PatientRepository
 * is the tenant-scoped existence guard for $patientId on the create branch.
 */
final class UpdatePatientContactHandler
{
    public function __construct(
        private readonly PatientContactRepository $contacts,
        private readonly PatientRepository $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(UpdatePatientContact $command): UpdatePatientContactResult
    {
        $existing = $this->contacts->findById($command->contactId); // RLS-scoped

        if ($existing !== null) {
            if ((string) $existing->patient_id !== $command->patientId) {
                // the client-minted id belongs to a different patient — do not leak which
                throw NotFound::aggregate(PatientContact::class, $command->contactId);
            }

            $this->patients->getById($command->patientId)->assertMutable(); // ADR-128 M-9 (blocks MERGED)

            $existing->revise(
                type: $command->type,
                value: $command->value,
                label: $command->label,
                isPreferred: $command->isPreferred,
                isActive: $command->isActive,
                actorId: $command->actorId,
            );
            $this->contacts->save($existing);

            return new UpdatePatientContactResult((string) $existing->id, false);
        }

        $this->patients->getById($command->patientId)->assertMutable(); // NotFound if missing / cross-tenant; ADR-128 M-9 (blocks MERGED)

        $contact = PatientContact::open(
            id: $command->contactId,
            tenantId: $this->context->tenantId(),
            patientId: $command->patientId,
            type: $command->type,
            value: $command->value,
            label: $command->label,
            isPreferred: $command->isPreferred,
            recordedByUserId: $command->actorId,
        );
        $this->contacts->save($contact);

        return new UpdatePatientContactResult((string) $contact->id, true);
    }
}
