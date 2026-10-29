<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\Gender;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class UpdatePerson implements AuthorizesAgainst
{
    public Gender $gender;

    public CarbonImmutable $dateOfBirth;

    public function __construct(
        public string $personId,
        public string $firstName,
        public ?string $middleName,
        public string $lastName,
        string|CarbonImmutable $dateOfBirth,
        string|Gender $gender,
        public ?string $nationalId = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $physicalAddress = null,
        public ?string $nextOfKinName = null,
        public ?string $nextOfKinRelationship = null,
        public ?string $nextOfKinPhone = null,
        public ?string $photoDocumentId = null,
    ) {
        $this->dateOfBirth = is_string($dateOfBirth)
            ? CarbonImmutable::parse($dateOfBirth)
            : $dateOfBirth;
        $this->gender = is_string($gender)
            ? Gender::from($gender)
            : $gender;
    }

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->personId;
    }
}
