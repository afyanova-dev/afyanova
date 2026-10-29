<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\Problem;
use App\Domains\Clinical\Domain\ValueObject\ProblemStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one patient's longitudinal problem list (12-clinical-domain.md §26,
 * §164; ADR-140 D-L). Protected (IC §6.2): problems are protected clinical
 * data and originating-facility-scoped (ADR-140 D-B / D-K).
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.problems.read`
 * check (`ProblemPolicy::view`, class-level). `GetPatientProblemsHandler`
 * resolves the caller's in-scope facilities and returns only problems whose
 * ORIGINATING facility is among them (ADR-140 D-B — originating-facility-only
 * visibility).
 *
 * `$status` (optional) narrows to `ACTIVE` or `RESOLVED`. There is NO
 * pagination in C.4 (ADR-140 D-L / clarification lock Decision 3) — the result
 * is a bounded `list<ProblemSummary>` for one patient, deterministically
 * ordered. NO `clin_rm_*` projection, NO `ActiveProblemsView`, NO
 * `GetProblemHistory` (all PHASE 16 / a later decision).
 */
#[ProtectedQuery(ability: 'view', subjectType: Problem::class)]
final readonly class GetPatientProblems
{
    /** A ProblemStatus value (`ACTIVE` | `RESOLVED`) to filter by, or null for all. */
    public ?string $status;

    public function __construct(public string $patientId, ?string $status = null)
    {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientProblems::$patientId must be a UUID (IC §3).');
        }

        if ($status !== null && ! ProblemStatus::isValid($status)) {
            throw new InvalidArgumentException('GetPatientProblems::$status must be ACTIVE, RESOLVED or null (ADR-140 D-E).');
        }

        $this->status = $status;
    }
}
