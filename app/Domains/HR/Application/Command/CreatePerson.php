<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\Gender;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class CreatePerson implements AuthorizesAgainst
{
    public string $personId;

    public Gender $gender;

    public CarbonImmutable $dateOfBirth;

    public function __construct(
        public string $firstName,
        public ?string $middleName,
        public string $lastName,
        string|CarbonImmutable $dateOfBirth,
        string|Gender $gender,
        ?string $personId = null,
        public ?string $nationalId = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $physicalAddress = null,
        public ?string $nextOfKinName = null,
        public ?string $nextOfKinRelationship = null,
        public ?string $nextOfKinPhone = null,
        public ?string $photoDocumentId = null,
    ) {
        $this->personId = $personId ?? (string) Str::uuid();
        $this->dateOfBirth = is_string($dateOfBirth)
            ? CarbonImmutable::parse($dateOfBirth)
            : $dateOfBirth;
        $this->gender = is_string($gender)
            ? Gender::from($gender)
            : $gender;
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }
}
