<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Verification source of a ProfessionalRegistration (25-hr-and-workforce-domain.md §6; Invariant 7.8, 7.14).
 */
enum VerificationSource: string
{
    case MANUAL = 'MANUAL';
    case COUNCIL_API = 'COUNCIL_API';
}
