<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The kind of patient address (10-patient-domain.md §33; ADR-126 D-5). §33
 * justifies exactly two — the physical residence and separate "Postal
 * Information where applicable" — plus an escape hatch. Additive-safe.
 */
#[TypeScript]
enum AddressType: string
{
    case Residential = 'RESIDENTIAL';
    case Postal = 'POSTAL';
    case Other = 'OTHER';
}
