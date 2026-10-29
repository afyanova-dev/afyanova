<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A patient's external / official identifier types (10-patient-domain.md §39,
 * §114). The tenant-scoped patient number is NOT in this list — it lives on
 * pat_patients.patient_number (ADR-114), not pat_patient_identifiers.
 *
 * §39 enumerates the concepts (national identifier, insurance member number,
 * passport, "other approved identifier") but not machine values; this is the
 * minimal set the shipped commands need. Additive-safe — a later ADR can
 * extend the case list without a data migration.
 */
#[TypeScript]
enum IdentifierType: string
{
    case NationalId = 'NATIONAL_ID';
    case InsuranceMember = 'INSURANCE_MEMBER';
    case Passport = 'PASSPORT';
    case Other = 'OTHER';

    /**
     * Sensitive types are masked by default and require
     * patient.identifiers.view_sensitive to reveal (ADR-122). Enforcement is
     * the Presentation layer's — PHASE 16; PHASE 5 only carries the flag.
     */
    public function isSensitive(): bool
    {
        return match ($this) {
            self::NationalId, self::InsuranceMember, self::Passport => true,
            self::Other => false,
        };
    }
}
