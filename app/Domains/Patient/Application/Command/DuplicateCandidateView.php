<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Command;

/**
 * The privacy-limited view of a potential-duplicate patient echoed back to the
 * caller so a human can resolve the REVIEW / BLOCK (ADR-117 PRIVACY: id,
 * number, display name, DOB — nothing more). Not an event payload; a
 * synchronous command result only.
 */
final readonly class DuplicateCandidateView
{
    public function __construct(
        public string $patientId,
        public string $patientNumber,
        public string $displayName,
        public ?string $dateOfBirth,
    ) {}
}
