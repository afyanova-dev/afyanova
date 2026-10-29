<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain\ValueObject;

/**
 * Professional classifications for cadres in AfyaNova (25-hr-and-workforce-domain.md §4; ADR-206).
 */
enum CadreCategory: string
{
    case CLINICAL = 'CLINICAL';
    case NURSING = 'NURSING';
    case ALLIED = 'ALLIED';
    case PHARMACY = 'PHARMACY';
    case LABORATORY = 'LABORATORY';
    case ADMIN = 'ADMIN';
    case SUPPORT = 'SUPPORT';
}
