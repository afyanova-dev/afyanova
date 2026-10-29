<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one clinical note's summary by id (12-clinical-domain.md §164; ADR-134).
 * Protected (IC §6.2): a clinical note is protected data by definition — its
 * body is clinical content and it is facility-scoped (§88, Invariant 24/25).
 *
 * The `#[ProtectedQuery]` gate is the COARSE tenant + `clinical.notes.read`
 * check (`ClinicalNotePolicy::view`, class-level — `App\Shared\Bus\Middleware\
 * AuthorizeQuery` never loads a subject). `GetClinicalNoteHandler` applies the
 * per-note facility refinement (ADR-133): a note at a facility outside the
 * caller's scope resolves as `NotFound`, never an authorization disclosure.
 *
 * NOT encounter-state-gated — a read is always available to an authorized
 * caller whatever the encounter's later state (Invariant 19; ADR-135). No side
 * effects: no event, no audit, no state mutation.
 */
#[ProtectedQuery(ability: 'view', subjectType: ClinicalNote::class)]
final readonly class GetClinicalNote
{
    public function __construct(public string $clinicalNoteId)
    {
        if (! Str::isUuid($clinicalNoteId)) {
            throw new InvalidArgumentException('GetClinicalNote::$clinicalNoteId must be a UUID (IC §3).');
        }
    }
}
