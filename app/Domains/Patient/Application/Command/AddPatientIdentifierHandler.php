<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Domains\Patient\Domain\PatientIdentifierRepository;
use App\Domains\Patient\Domain\PatientRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * The PatientIdentifierRepository is listed first so
 * AuthorizeCommand::ownerRepositoryType() (IC §5.1) resolves the aggregate as
 * PatientIdentifier — a class-level `add` check (subjectId() === null).
 * PatientRepository is the tenant-scoped existence guard for $patientId.
 */
final class AddPatientIdentifierHandler
{
    public function __construct(
        private readonly PatientIdentifierRepository $identifiers,
        private readonly PatientRepository $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(AddPatientIdentifier $command): AddPatientIdentifierResult
    {
        $this->patients->getById($command->patientId)->assertMutable(); // RLS-scoped; NotFound if missing / cross-tenant; ADR-128 M-9 (blocks MERGED)

        $identifier = PatientIdentifier::add(
            id: $command->identifierId,
            tenantId: $this->context->tenantId(),
            patientId: $command->patientId,
            type: $command->type,
            value: $command->value,
            issuingAuthority: $command->issuingAuthority,
            effectiveFrom: $command->effectiveFrom,
            effectiveTo: $command->effectiveTo,
        );

        $this->identifiers->save($identifier);

        return new AddPatientIdentifierResult((string) $identifier->id);
    }
}
