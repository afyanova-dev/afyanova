<?php

declare(strict_types=1);

namespace App\Domains\HR\Application\Command;

use App\Domains\HR\Domain\ValueObject\VerificationSource;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;

final readonly class RecordRegistrationVerification implements AuthorizesAgainst
{
    public CarbonImmutable $verifiedAt;

    public VerificationSource $verificationSource;

    public function __construct(
        public string $registrationId,
        string|CarbonImmutable $verifiedAt,
        string|VerificationSource $verificationSource = VerificationSource::MANUAL,
        public ?string $notes = null,
    ) {
        $this->verifiedAt = is_string($verifiedAt) ? CarbonImmutable::parse($verifiedAt) : $verifiedAt;
        $this->verificationSource = is_string($verificationSource) ? VerificationSource::from($verificationSource) : $verificationSource;
    }

    public function policyAbility(): string
    {
        return 'recordVerification';
    }

    public function subjectId(): string
    {
        return $this->registrationId;
    }
}
