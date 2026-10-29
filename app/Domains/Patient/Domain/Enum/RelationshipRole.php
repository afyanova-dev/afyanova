<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The coded role a related person plays for a patient (10-patient-domain.md
 * §35-§38; ADR-126). Closed set = the canonical §38 vocabulary.
 *
 * EMERGENCY_CONTACT, NEXT_OF_KIN and GUARDIAN are DISTINCT roles — never
 * synonyms: an emergency contact carries no assumed legal authority (§35),
 * next of kin is a separate legal/operational concept (§36), and a guardian
 * is an explicit responsible-person relationship (§37).
 *
 * Additive-safe — a later ADR may extend this list without a data migration.
 */
#[TypeScript]
enum RelationshipRole: string
{
    case Parent = 'PARENT';
    case Child = 'CHILD';
    case Spouse = 'SPOUSE';
    case Guardian = 'GUARDIAN';
    case Caregiver = 'CAREGIVER';
    case EmergencyContact = 'EMERGENCY_CONTACT';
    case NextOfKin = 'NEXT_OF_KIN';
}
