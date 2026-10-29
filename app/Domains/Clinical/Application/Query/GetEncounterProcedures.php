<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one encounter's procedures (13-clinical-procedures-domain.md §164;
 * ADR-146 D-Q). Protected (IC §6.2): procedures are protected clinical data and
 * originating-facility-scoped.
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.procedures.read`
 * check. `GetEncounterProceduresHandler` resolves the caller's in-scope
 * facilities and returns only procedures whose ORIGINATING facility is among
 * them (the `GetEncounterTriageAssessments` precedent). NO pagination (a
 * bounded, deterministically-ordered `list<ProcedureSummary>`). NOT
 * encounter-state-gated.
 */
#[ProtectedQuery(ability: 'view', subjectType: ClinicalProcedure::class)]
final readonly class GetEncounterProcedures
{
    public function __construct(public string $encounterId)
    {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('GetEncounterProcedures::$encounterId must be a UUID (IC §3).');
        }
    }
}
