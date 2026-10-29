<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What happened to the patient at the end of the attendance
 * (11-encounter-domain.md §76, §77; ADR-130 A). A closed enum for PHASE 5 —
 * exactly the coherent §76 set. Distinct from {@see EncounterStatus}: the
 * status says the interaction ended, the disposition says what came next.
 *
 * `REFERRED` is a disposition, NEVER a status (ADR-121). `DECEASED` here records
 * the encounter outcome only — the identity-level death is Patient's
 * `MarkPatientDeceased`.
 *
 * Not set by `CreateEncounter`. `CompleteEncounter` requires it non-null;
 * `AdmitEncounter` sets `ADMITTED`; `TransferEncounter` sets `TRANSFERRED`
 * (later increments). Additive-safe — a later ADR may add a case.
 */
#[TypeScript]
enum EncounterDisposition: string
{
    case Discharged = 'DISCHARGED';
    case Admitted = 'ADMITTED';
    case Referred = 'REFERRED';
    case Transferred = 'TRANSFERRED';
    case Left = 'LEFT';
    case FollowUp = 'FOLLOW_UP';
    case Deceased = 'DECEASED';
    case Other = 'OTHER';
}
