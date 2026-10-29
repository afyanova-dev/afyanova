<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Detailed demographic and contact record for Person (25-hr-and-workforce-domain.md §2).
 */
final readonly class PersonDetail
{
    public function __construct(
        public string $personId,
        public string $tenantId,
        public string $firstName,
        public ?string $middleName,
        public string $lastName,
        public string $dateOfBirth,
        public string $gender,
        public ?string $nationalId,
        public ?string $phone,
        public ?string $email,
        public ?string $physicalAddress,
        public ?string $photoDocumentId,
        public ?string $nextOfKinName,
        public ?string $nextOfKinRelationship,
        public ?string $nextOfKinPhone,
        public int $version,
    ) {}
}
