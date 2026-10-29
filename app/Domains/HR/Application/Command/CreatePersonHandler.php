<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\Model\Person;
use App\Domains\HR\Domain\PersonRepository;
use App\Shared\Tenancy\TenantContext;

/**
 * Handles CreatePerson command (ADR-206).
 */
final class CreatePersonHandler
{
    public function __construct(
        private readonly PersonRepository $persons,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(CreatePerson $command): void
    {
        $person = Person::create(
            id: $command->personId,
            tenantId: $this->context->tenantId(),
            firstName: $command->firstName,
            middleName: $command->middleName,
            lastName: $command->lastName,
            dateOfBirth: $command->dateOfBirth,
            gender: $command->gender,
            nationalId: $command->nationalId,
            phone: $command->phone,
            email: $command->email,
            physicalAddress: $command->physicalAddress,
            nextOfKinName: $command->nextOfKinName,
            nextOfKinRelationship: $command->nextOfKinRelationship,
            nextOfKinPhone: $command->nextOfKinPhone,
            photoDocumentId: $command->photoDocumentId,
        );

        $this->persons->save($person);
    }
}
