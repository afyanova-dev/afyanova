<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateReferral` targets EXTERNAL (26-care-coordination-domain.md §7
 * Invariant 7.10/7.11) without a `consentBasisRef` — required for EXTERNAL
 * and cross-tenant referrals (the consent record itself is owned by
 * Patient). Maps to HTTP 409.
 */
final class ConsentRequiredForReferral extends DomainException
{
    public static function forExternalTarget(): self
    {
        return new self(
            'CreateReferral targeting EXTERNAL requires a non-null consentBasisRef (Invariant 7.10).',
        );
    }

    public function errorCode(): string
    {
        return 'consent_required_for_referral';
    }
}
