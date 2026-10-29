<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Event;

/**
 * Emitted when a professional registration verification is recorded (25-hr-and-workforce-domain.md §11; ADR-206).
 */
final readonly class RegistrationVerified
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $registrationId,
        public string $staffId,
        public string $council,
        public string $licenceNumber,
        public string $verifiedAt,
        public string $verificationSource,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
