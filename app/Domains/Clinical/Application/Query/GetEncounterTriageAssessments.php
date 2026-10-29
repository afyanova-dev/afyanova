<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one encounter's triage assessments (12-clinical-domain.md §164; ADR-145
 * D-H). Protected (IC §6.2): triage assessments are protected clinical data and
 * originating-facility-scoped (ADR-145 D-P).
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.triage.read`
 * check (`TriageAssessmentPolicy::view`, class-level).
 * `GetEncounterTriageAssessmentsHandler` resolves the caller's in-scope
 * facilities and returns only assessments whose ORIGINATING facility is among
 * them (ADR-145 D-P — originating-facility-only visibility).
 *
 * `$currentOnly` (default false) returns every assessment for the encounter
 * (the re-triage chain, including superseded ones); true returns only
 * assessments with `superseded_at IS NULL` — the "current triage" read a
 * clinician picking up the patient needs (ADR-145 D-H). There is NO pagination
 * (a bounded, deterministically-ordered `list<TriageAssessmentSummary>`). NO
 * `clin_rm_*` projection, NO patient-level / timeline reader (PHASE 16 / the
 * Queueing PHASE-12 consumer).
 */
#[ProtectedQuery(ability: 'view', subjectType: TriageAssessment::class)]
final readonly class GetEncounterTriageAssessments
{
    public function __construct(
        public string $encounterId,
        public bool $currentOnly = false,
    ) {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('GetEncounterTriageAssessments::$encounterId must be a UUID (IC §3).');
        }
    }
}
