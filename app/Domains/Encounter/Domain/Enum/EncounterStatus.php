<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Encounter lifecycle state (11-encounter-domain.md §19; ADR-121). The nine
 * cases are fixed by ADR-121. `CreateEncounter` produces `ARRIVED` by default
 * and `PLANNED` only on an explicit planned intent; every later transition is
 * bound to a specific command (ADR-121's command→state table).
 *
 * `ARRIVED` and `ACTIVE` are the "active-attendance" set — the partial unique
 * index `enc_one_active_per_patient_facility` covers exactly those two
 * (Invariant 12b). `REFERRED` is reserved for a future workflow; in PHASE 5 a
 * refer-out is disposition `REFERRED` + `COMPLETED` (ADR-121 / ADR-130).
 */
#[TypeScript]
enum EncounterStatus: string
{
    case Planned = 'PLANNED';
    case Arrived = 'ARRIVED';
    case Active = 'ACTIVE';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case LeftWithoutBeingSeen = 'LEFT_WITHOUT_BEING_SEEN';
    case Abandoned = 'ABANDONED';
    case Transferred = 'TRANSFERRED';
    case Referred = 'REFERRED';
}
