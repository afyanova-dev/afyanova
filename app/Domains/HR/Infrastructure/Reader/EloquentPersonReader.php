<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use App\Domains\HR\Contracts\Dto\PersonDetail;
use App\Domains\HR\Domain\Exception\PersonNotFoundException;
use App\Domains\HR\Domain\Model\Person;
use App\Domains\HR\Domain\PersonReader;

/**
 * Eloquent implementation of PersonReader (25-hr-and-workforce-domain.md §2).
 */
final class EloquentPersonReader implements PersonReader
{
    public function exists(string $personId): bool
    {
        return Person::query()->whereKey($personId)->exists();
    }

    public function detail(string $personId): PersonDetail
    {
        /** @var Person|null $person */
        $person = Person::query()->whereKey($personId)->first();

        if ($person === null) {
            throw PersonNotFoundException::forId($personId);
        }

        return new PersonDetail(
            personId: $person->id,
            tenantId: $person->tenant_id,
            firstName: $person->first_name,
            middleName: $person->middle_name,
            lastName: $person->last_name,
            dateOfBirth: $person->date_of_birth->toDateString(),
            gender: $person->gender->value,
            nationalId: $person->national_id,
            phone: $person->phone,
            email: $person->email,
            physicalAddress: $person->physical_address,
            photoDocumentId: $person->photo_document_id !== null ? (string) $person->photo_document_id : null,
            nextOfKinName: $person->next_of_kin_name,
            nextOfKinRelationship: $person->next_of_kin_relationship,
            nextOfKinPhone: $person->next_of_kin_phone,
            version: $person->version,
        );
    }
}
