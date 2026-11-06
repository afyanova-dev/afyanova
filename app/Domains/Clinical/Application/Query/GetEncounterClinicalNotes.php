<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one encounter's clinical notes for the clinician workspace read-back.
 * Protected (IC §6.2): notes are protected clinical data and
 * originating-facility-scoped.
 *
 * The `#[ProtectedQuery]` gate is the coarse tenant + `clinical.notes.read`
 * check. The handler narrows to facilities authorized in the current context;
 * an empty scope yields an empty list rather than an authorization disclosure.
 */
#[ProtectedQuery(ability: 'view', subjectType: ClinicalNote::class)]
final readonly class GetEncounterClinicalNotes
{
    public function __construct(public string $encounterId)
    {
        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('GetEncounterClinicalNotes::$encounterId must be a UUID (IC §3).');
        }
    }
}
