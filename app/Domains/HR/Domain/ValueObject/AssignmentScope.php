<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Organizational scope of an Assignment (25-hr-and-workforce-domain.md §6, §7; Invariant 7.5, 7.7).
 */
enum AssignmentScope: string
{
    case FACILITY = 'FACILITY';
    case DEPARTMENT = 'DEPARTMENT';
    case UNIT = 'UNIT';
}
