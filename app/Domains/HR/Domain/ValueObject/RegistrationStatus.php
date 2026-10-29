<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Status of a ProfessionalRegistration reflecting council authority (25-hr-and-workforce-domain.md §8; Invariant 7.8, 7.14).
 *
 * ACTIVE -> EXPIRED / LAPSED / SUSPENDED_BY_COUNCIL.
 */
enum RegistrationStatus: string
{
    case ACTIVE = 'ACTIVE';
    case EXPIRED = 'EXPIRED';
    case LAPSED = 'LAPSED';
    case SUSPENDED_BY_COUNCIL = 'SUSPENDED_BY_COUNCIL';

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
