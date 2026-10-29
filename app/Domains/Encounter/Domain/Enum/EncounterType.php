<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The nature of the attendance (11-encounter-domain.md §10; ADR-120). Describes
 * why the patient is here, not what happens inside the visit — an OUTPATIENT
 * encounter may still contain lab, imaging, a procedure and pharmacy.
 *
 * The PHASE 5 set is exactly these eleven (ADR-120). Four map to an
 * `org_capabilities` code that `CreateEncounter` requires ENABLED
 * ({@see requiredCapability()}); the other seven are not capability-gated in
 * PHASE 5 (no code exists for them and PHASE 5 does not invent one). Additive-
 * safe: a later ADR may extend the list.
 */
#[TypeScript]
enum EncounterType: string
{
    case Outpatient = 'OUTPATIENT';
    case Emergency = 'EMERGENCY';
    case Inpatient = 'INPATIENT';
    case Maternity = 'MATERNITY';
    case Diagnostic = 'DIAGNOSTIC';
    case Procedure = 'PROCEDURE';
    case DayCare = 'DAY_CARE';
    case FollowUp = 'FOLLOW_UP';
    case Telehealth = 'TELEHEALTH';
    case Referral = 'REFERRAL';
    case Other = 'OTHER';

    /**
     * The `org_capabilities` code the target facility must have ENABLED before a
     * `CreateEncounter` of this type is allowed (ADR-120), or `null` when the
     * type is not capability-gated in PHASE 5.
     */
    public function requiredCapability(): ?string
    {
        return match ($this) {
            self::Outpatient => 'OUTPATIENT_CARE',
            self::Emergency => 'EMERGENCY_CARE',
            self::Inpatient => 'INPATIENT_CARE',
            self::Maternity => 'MATERNITY',
            default => null,
        };
    }
}
