<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one diagnosis's summary by id (12-clinical-domain.md §164; ADR-139 D12).
 * Protected (IC §6.2): a diagnosis is protected clinical data — its code and
 * description are clinical detail and it is facility-scoped (§88, Invariant
 * 24/25).
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.diagnoses.read`
 * check (`DiagnosisPolicy::view`, class-level — the QueryBus never loads a
 * subject). `GetDiagnosisHandler` applies the per-diagnosis facility refinement
 * (ADR-139): a diagnosis at a facility outside the caller's scope resolves as
 * `NotFound`, never an authorization disclosure.
 *
 * The ONLY C.3 query — no encounter-diagnoses / current-diagnoses / latest /
 * trend / history reads and no `clin_rm_*` projection (PHASE 16). No side
 * effects: no event, no audit, no state mutation. NOT encounter-state-gated.
 */
#[ProtectedQuery(ability: 'view', subjectType: Diagnosis::class)]
final readonly class GetDiagnosis
{
    public function __construct(public string $diagnosisId)
    {
        if (! Str::isUuid($diagnosisId)) {
            throw new InvalidArgumentException('GetDiagnosis::$diagnosisId must be a UUID (IC §3).');
        }
    }
}
