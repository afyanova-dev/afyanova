<?php

declare(strict_types=1);

namespace App\Domains\Patient\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Patient identity lifecycle (10-patient-domain.md §44; ADR-116).
 *
 * PHASE 5 scope is exactly these four cases. `ARCHIVED` (the retention sense
 * of §46) is out of PHASE 5 — no command produces it (ADR-116).
 *
 * Legal transitions (ADR-116):
 *   ACTIVE <-> INACTIVE
 *   {ACTIVE,INACTIVE} -> DECEASED           (terminal in PHASE 5)
 *   {ACTIVE,INACTIVE} -> MERGED             (terminal except via unmerge)
 *   MERGED -> {ACTIVE,INACTIVE}             (RequestUnmerge only — later slice)
 */
#[TypeScript]
enum PatientStatus: string
{
    case Active = 'ACTIVE';
    case Inactive = 'INACTIVE';
    case Deceased = 'DECEASED';
    case Merged = 'MERGED';
}
