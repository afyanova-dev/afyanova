<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Enum;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Clinical procedure lifecycle state (13-clinical-procedures-domain.md §32,
 * §138, §206; ADR-137, ADR-146 D-F).
 *
 * The PHASE 6 (CP.1) set, minus Scheduling:
 *
 *   - `REQUESTED`    — the procedure has been ordered but not yet authorized;
 *   - `AUTHORIZED`   — authorized for execution (13 §21 — always an explicit
 *                      transition);
 *   - `READY`        — prepared for execution; entered ONLY when the
 *                      definition's `requires_readiness_check` is true
 *                      (ADR-146 D-F) — otherwise `AUTHORIZED → IN_PROGRESS`
 *                      is legal directly;
 *   - `IN_PROGRESS`  — execution has started;
 *   - `COMPLETED`    — execution finished; core facts become historical
 *                      (13 §139, Inv 6);
 *   - `CANCELLED`    — abandoned before execution (from `REQUESTED` /
 *                      `AUTHORIZED` / `READY`);
 *   - `ABORTED`      — stopped after starting (from `IN_PROGRESS` only;
 *                      `abort_reason` mandatory);
 *   - `DECLINED`     — the patient refused (from `AUTHORIZED` or `READY` —
 *                      ADR-146 D-F, an ADR-137 state-machine refinement made
 *                      necessary by `READY` being definition-conditional).
 *
 * `SCHEDULED` / `NO_SHOW` are deferred (Scheduling — PHASE 11; ADR-137). Every
 * transition passes `Clinical\Domain\Service\ClinicalProcedureLifecycle`.
 */
#[TypeScript]
enum ClinicalProcedureStatus: string
{
    case Requested = 'REQUESTED';
    case Authorized = 'AUTHORIZED';
    case Ready = 'READY';
    case InProgress = 'IN_PROGRESS';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case Aborted = 'ABORTED';
    case Declined = 'DECLINED';
}
