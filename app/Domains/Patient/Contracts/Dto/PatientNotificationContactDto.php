<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

final readonly class PatientNotificationContactDto
{
    public function __construct(
        public string $patientId,
        public ?string $phone,
        public ?string $email,
        public bool $hasCommunicationConsent,
        public string $preferredLanguage = 'sw',
    ) {}
}
