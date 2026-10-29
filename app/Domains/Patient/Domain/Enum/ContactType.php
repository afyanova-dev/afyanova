<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The channel a secondary patient contact point uses (10-patient-domain.md
 * §31-§32; ADR-126 D-5). Closed, channel-based set:
 *
 *   MOBILE   — a phone number that can receive SMS (§32 makes this matter)
 *   LANDLINE — a phone number that cannot
 *   EMAIL    — an email address
 *   OTHER    — any other channel
 *
 * Drives normalization (phone -> digits, email -> folded). Additive-safe.
 */
#[TypeScript]
enum ContactType: string
{
    case Mobile = 'MOBILE';
    case Landline = 'LANDLINE';
    case Email = 'EMAIL';
    case Other = 'OTHER';

    public function isEmail(): bool
    {
        return $this === self::Email;
    }
}
