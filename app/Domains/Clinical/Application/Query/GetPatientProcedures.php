<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one patient's procedure history across encounters
 * (13-clinical-procedures-domain.md §164; ADR-146 D-Q). Protected (IC §6.2):
 * procedures are protected clinical data and originating-facility-scoped.
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.procedures.read`
 * check. `GetPatientProceduresHandler` resolves the caller's in-scope
 * facilities and returns only procedures whose ORIGINATING facility is among
 * them (the `GetPatientProblems` precedent). NO pagination.
 */
#[ProtectedQuery(ability: 'view', subjectType: ClinicalProcedure::class)]
final readonly class GetPatientProcedures
{
    public function __construct(public string $patientId)
    {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientProcedures::$patientId must be a UUID (IC §3).');
        }
    }
}
