<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Whether an identifier value is merely entered data or verified identity
 * evidence (10-patient-domain.md §42; ADR-117). Only VERIFIED rows are
 * uniqueness-constrained (ADR-117) — an unverified value may be mistyped
 * (§43) and must not block a legitimate registration.
 */
#[TypeScript]
enum IdentifierVerificationStatus: string
{
    case Unverified = 'UNVERIFIED';
    case Verified = 'VERIFIED';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
}
