<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Professional registration summary (25-hr-and-workforce-domain.md §3; ADR-206).
 */
final readonly class ProfessionalRegistrationSummary
{
    public function __construct(
        public string $registrationId,
        public string $tenantId,
        public string $staffId,
        public string $council,
        public string $category,
        public string $licenceNumber,
        public string $issuedOn,
        public string $expiresOn,
        public ?string $verifiedAt,
        public string $verificationSource,
        public string $status,
        public ?string $notes,
        public int $version,
    ) {}
}
