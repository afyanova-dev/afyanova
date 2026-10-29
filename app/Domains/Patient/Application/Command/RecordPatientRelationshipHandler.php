<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

use App\Domains\Patient\Domain\Model\PatientRelationship;
use App\Domains\Patient\Domain\PatientRelationshipRepository;
use App\Domains\Patient\Domain\PatientRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * PatientRelationshipRepository is listed first so
 * AuthorizeCommand::ownerRepositoryType() (IC §5.1) resolves the aggregate as
 * PatientRelationship for the class-level `recordRelationship` check.
 * PatientRepository is the tenant-scoped existence guard for $patientId.
 */
final class RecordPatientRelationshipHandler
{
    public function __construct(
        private readonly PatientRelationshipRepository $relationships,
        private readonly PatientRepository $patients,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RecordPatientRelationship $command): RecordPatientRelationshipResult
    {
        $this->patients->getById($command->patientId)->assertMutable(); // RLS-scoped; NotFound if missing / cross-tenant; ADR-128 M-9 (blocks MERGED)

        $relationship = PatientRelationship::record(
            id: $command->relationshipId,
            tenantId: $this->context->tenantId(),
            patientId: $command->patientId,
            role: $command->role,
            relatedName: $command->relatedName,
            relationshipLabel: $command->relationshipLabel,
            relatedPhone: $command->relatedPhone,
            note: $command->note,
            recordedByUserId: $command->actorId,
        );

        $this->relationships->save($relationship);

        return new RecordPatientRelationshipResult((string) $relationship->id);
    }
}
