<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Types of employment contracts in AfyaNova (25-hr-and-workforce-domain.md §3; ADR-206).
 */
enum ContractType: string
{
    case PERMANENT = 'PERMANENT';
    case FIXED_TERM = 'FIXED_TERM';
    case CASUAL = 'CASUAL';
    case LOCUM = 'LOCUM';
    case VOLUNTEER = 'VOLUNTEER';
    case INTERN = 'INTERN';
}
