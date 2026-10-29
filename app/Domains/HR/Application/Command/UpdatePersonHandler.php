<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\PersonRepository;

/**
 * Handles UpdatePerson command (ADR-206).
 */
final class UpdatePersonHandler
{
    public function __construct(
        private readonly PersonRepository $persons,
    ) {}

    public function __invoke(UpdatePerson $command): void
    {
        $person = $this->persons->getByIdForUpdate($command->personId);

        $person->updateDetails(
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
        );
        if ($command->photoDocumentId !== null) {
            $person->updatePhoto($command->photoDocumentId);
        }

        $this->persons->save($person);
    }
}
