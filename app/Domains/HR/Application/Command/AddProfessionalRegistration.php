<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final readonly class AddProfessionalRegistration implements AuthorizesAgainst
{
    public string $registrationId;

    public CarbonImmutable $issuedOn;

    public CarbonImmutable $expiresOn;

    public function __construct(
        public string $staffId,
        public string $council,
        public string $category,
        public string $licenceNumber,
        string|CarbonImmutable $issuedOn,
        string|CarbonImmutable $expiresOn,
        public ?string $notes = null,
        ?string $registrationId = null,
    ) {
        $this->registrationId = $registrationId ?? (string) Str::uuid();
        $this->issuedOn = is_string($issuedOn) ? CarbonImmutable::parse($issuedOn) : $issuedOn;
        $this->expiresOn = is_string($expiresOn) ? CarbonImmutable::parse($expiresOn) : $expiresOn;
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
