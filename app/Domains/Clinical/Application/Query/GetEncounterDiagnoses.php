<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one encounter's current diagnoses for the clinician workspace read-back.
 * Protected (IC §6.2) and originating-facility-scoped.
 *
 * The `#[ProtectedQuery]` gate is the coarse tenant + `clinical.diagnoses.read`
 * check. The handler narrows to facilities authorized in the current context.
 */
#[ProtectedQuery(ability: 'view', subjectType: Diagnosis::class)]
final readonly class GetEncounterDiagnoses
{
    public function __construct(public string $encounterId)
    {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('GetEncounterDiagnoses::$encounterId must be a UUID (IC §3).');
        }
    }
}
